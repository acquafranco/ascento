<?php

namespace Tests\Feature\Stage7;

use App\Filament\Pages\PublicQrPage;
use App\Models\BuildingPublicPage;
use App\Models\Elevator;
use App\Models\ParachuteTest;
use App\Models\User;
use App\Notifications\App\ParachuteReminderNotification;
use App\Services\Parachute\ParachuteService;
use App\Services\PublicQr\PublicQrService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Ajustes de la Etapa 7: el recordatorio de paracaídas llega también por
 * push; el QR muestra la prueba prevista cuando corresponde avisarla
 * (próxima o vencida, la misma cuenta del recordatorio); y el SuperAdmin
 * ve los QR de todas las empresas y entra a la de cada uno para abrirlo.
 */
class ParachuteQrAdjustmentsTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->elevator = $this->elevatorOf($this->a, 'Equipo P1');
    }

    private function elevatorOf(array $tenant, string $label): Elevator
    {
        $elevator = (new Elevator)->forceFill(['building_id' => $tenant['building']->id, 'company_id' => $tenant['company']->id, 'label' => $label, 'kind' => 'elevator', 'is_active' => true]);
        $elevator->save();

        return $elevator;
    }

    private function inPanel(User $user, ?int $selected = null): void
    {
        $this->flushSession();
        $this->actingAs($user);
        if ($selected) {
            session(['selected_company_id' => $selected]);
        }
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    /** Prueba satisfactoria y periodicidad del equipo (como lo carga el admin). */
    private function policy(string $performedOn, int $months, int $notice = 30, ?Elevator $elevator = null, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        $this->inPanel($tenant['admin']);
        $service = app(ParachuteService::class);
        $service->record($tenant['admin'], ['elevator_id' => ($elevator ?? $this->elevator)->id, 'performed_on' => $performedOn, 'result' => ParachuteTest::SATISFACTORY, 'performed_by_id' => $tenant['technician']->id]);
        $service->configure($tenant['admin'], $elevator ?? $this->elevator, ['interval_months' => $months, 'notice_days' => $notice]);
    }

    private function publish(?array $tenant = null): BuildingPublicPage
    {
        $tenant ??= $this->a;
        $this->inPanel($tenant['admin']);
        $service = app(PublicQrService::class);
        $page = $service->generate($tenant['admin'], $tenant['building']);
        $page = $service->updateSections($tenant['admin'], $page, ['show_safety_tests' => true]);
        auth()->logout();

        return $page;
    }

    private function scan(BuildingPublicPage $page): string
    {
        $this->flushSession();

        return $this->get('/qr/'.$page->fresh()->token)->assertOk()->getContent();
    }

    public function test_parachute_reminders_reach_the_bell_and_push_once(): void
    {
        $this->configureVapid();
        $this->fakePushService();
        $this->policy('2025-11-01', 12);
        $owner = $this->subscribeDevice($this->a['admin']);
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $other = $this->subscribeDevice($b['admin']);
        auth()->logout();

        $this->artisan('parachute:remind')->assertSuccessful();
        $this->artisan('parachute:remind')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')->where('type', ParachuteReminderNotification::class)->where('notifiable_id', $this->a['admin']->id)->count());
        $this->assertPushDeliveredTo($owner);
        $this->assertNoPushDeliveredTo($other);
        $this->assertCount(1, $this->deliveredEndpoints()); // la segunda corrida no repite el push
        $this->assertTrue((new ParachuteReminderNotification('upcoming', 1, 'E', 'B', '2026-11-01', '/admin'))->wantsPush());
    }

    public function test_the_qr_shows_the_planned_test_only_when_it_is_due(): void
    {
        // Vence el 01/11/2026 con 10 días de aviso: al 14/10 todavía está al día, el QR no la anuncia.
        $this->policy('2025-11-01', 12, 10);
        $page = $this->publish();
        $html = $this->scan($page);
        $this->assertStringContainsString('Prueba de paracaídas registrada el 01/11/2025', $html);
        $this->assertStringNotContainsString('data-qr="next-test"', $html);

        // Dentro de los días de aviso (el mismo día que sale el recordatorio): próxima prevista.
        $this->travelTo(Carbon::parse('2026-10-25 09:00'));
        $this->assertSame(ParachuteService::UPCOMING, app(ParachuteService::class)->overview($this->a['company']->id)->first(fn ($row) => $row['elevator']->id === $this->elevator->id)['status']);
        $this->assertStringContainsString('Próxima prueba de paracaídas prevista: 01/11/2026', $this->scan($page));

        // Pasada la fecha sin registro: prevista y todavía sin registrar.
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $html = $this->scan($page);
        $this->assertStringContainsString('Prueba de paracaídas prevista para el 01/11/2026, todavía sin registrar', $html);
        $this->assertStringContainsString('Observación pendiente', $html);

        // Registrada la prueba nueva: vuelve a estar al día y el aviso desaparece del QR.
        $this->inPanel($this->a['admin']);
        app(ParachuteService::class)->record($this->a['admin'], ['elevator_id' => $this->elevator->id, 'performed_on' => '2026-11-02', 'result' => ParachuteTest::SATISFACTORY, 'performed_by_name' => 'Técnico']);
        auth()->logout();
        $html = $this->scan($page);
        $this->assertStringContainsString('Prueba de paracaídas registrada el 02/11/2026', $html);
        $this->assertStringNotContainsString('data-qr="next-test"', $html);
    }

    public function test_the_planned_test_respects_the_publish_switch_and_company(): void
    {
        $this->policy('2025-11-01', 12); // próxima al 14/10
        $page = $this->publish();

        // Sin «Pruebas de paracaídas» publicadas, no aparece.
        $this->inPanel($this->a['admin']);
        app(PublicQrService::class)->updateSections($this->a['admin'], $page, ['show_safety_tests' => false]);
        auth()->logout();
        $this->assertStringNotContainsString('prevista', $this->scan($page));

        // Sin periodicidad definida: no hay fecha que anunciar.
        $this->inPanel($this->a['admin']);
        app(PublicQrService::class)->updateSections($this->a['admin'], $page, ['show_safety_tests' => true]);
        app(ParachuteService::class)->configure($this->a['admin'], $this->elevator, ['interval_months' => null]);
        auth()->logout();
        $this->assertStringNotContainsString('data-qr="next-test"', $this->scan($page));

        // Otra empresa con un equipo vencido: no se mezcla con el QR de A, y el de B muestra el suyo.
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $bElevator = $this->elevatorOf($b, 'Equipo de B');
        $this->policy('2025-01-01', 12, 30, $bElevator, $b);
        $bPage = $this->publish($b);
        $this->assertStringNotContainsString('Equipo de B', $this->scan($page));
        $this->assertStringContainsString('prevista para el 01/01/2026, todavía sin registrar', $this->scan($bPage));
    }

    public function test_the_superadmin_sees_every_qr_and_opens_one_inside_its_company(): void
    {
        $page = $this->publish();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $bPage = $this->publish($b);
        $this->inPanel($b['admin']);
        app(PublicQrService::class)->deactivate($b['admin'], $bPage);
        auth()->logout();
        $super = User::factory()->superAdmin()->create();

        // Sin empresa elegida: los QR de todas, con su estado; el enlace público solo del activo.
        $this->inPanel($super);
        $html = $this->get('/admin/qr-publicos')->assertOk()->getContent();
        $this->assertStringContainsString('data-qr-platform', $html);
        $this->assertStringContainsString(e($this->a['building']->name), $html);
        $this->assertStringContainsString(e($b['company']->name), $html);
        $this->assertStringContainsString($page->fresh()->publicUrl(), $html);
        $this->assertStringNotContainsString($bPage->fresh()->publicUrl(), $html);

        // «Abrir» entra a la empresa del QR y muestra su detalle con la vista previa.
        Livewire::test(PublicQrPage::class)->call('openForSuperAdmin', $page->id)
            ->assertRedirect(PublicQrPage::getUrl(['edificio' => $this->a['building']->id]));
        $this->assertSame($this->a['company']->id, (int) session('selected_company_id'));
        $this->get('/admin/qr-publicos?edificio='.$this->a['building']->id)->assertOk()->assertSee('data-qr-preview', false);

        // Con una empresa elegida, la lista es la de esa empresa, como siempre.
        $this->assertStringNotContainsString('data-qr-platform', $this->get('/admin/qr-publicos')->assertOk()->getContent());

        // Nadie más usa «Abrir»: ni el dueño ni un administrativo.
        $this->inPanel($this->a['admin']);
        Livewire::test(PublicQrPage::class)->call('openForSuperAdmin', $bPage->id)->assertForbidden();
        $this->assertNull(session('selected_company_id'));
        $this->assertStringNotContainsString('data-qr-platform', $this->get('/admin/qr-publicos')->assertOk()->getContent());
    }
}
