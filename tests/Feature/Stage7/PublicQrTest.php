<?php

namespace Tests\Feature\Stage7;

use App\Filament\Pages\PublicQrPage;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ParachuteTest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PublicQr\PublicQrService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 7.1 y 7.2: QR público de un edificio. Solo lectura, solo lo
 * autorizado, token aleatorio que se invalida al desactivar o regenerar,
 * límite de solicitudes, sin acceso al portal ni al panel, permisos en el
 * servidor y nada entre empresas.
 */
class PublicQrTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Building $building;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->a['company']->update(['name' => 'Ascensores Norte', 'phone' => '011 4000-1000', 'email' => 'contacto@norte.test']);
        $this->building = $this->a['building'];
        $this->building->update(['name' => 'Torre Libertad', 'address' => 'Av. Libertador 1200', 'contact_person' => 'Sra. Privada', 'phone' => '11-5555-0000', 'notes' => 'Nota interna secreta']);
        Client::whereKey($this->building->client_id)->update(['name' => 'Consorcio Confidencial', 'phone' => '11-9999-9999']);
        $this->elevator = Elevator::withoutGlobalScopes()->where('building_id', $this->building->id)->first()
            ?? tap((new Elevator)->forceFill(['building_id' => $this->building->id, 'company_id' => $this->a['company']->id, 'label' => 'Ascensor 1', 'kind' => 'elevator', 'is_active' => true]))->save();
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name = 'Ana Oficina'): User
    {
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $this->actingAs($this->a['admin']);
        $user = app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
        $this->inPanel($user);

        return $user;
    }

    private function generate(?array $sections = null): BuildingPublicPage
    {
        $this->inPanel($this->a['admin']);
        $page = app(PublicQrService::class)->generate($this->a['admin'], $this->building);

        return $sections ? app(PublicQrService::class)->updateSections($this->a['admin'], $page, $sections) : $page;
    }

    private function denied(\Closure $call, int $status): void
    {
        try {
            $call();
            $this->fail("Debía responder {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    /** Pedido público, sin sesión. */
    private function visit(string $token)
    {
        auth()->logout();
        $this->flushSession();

        return $this->get('/qr/'.$token);
    }

    public function test_the_public_page_shows_only_authorized_information_and_is_read_only(): void
    {
        // Datos privados alrededor: un remito firmado con notas y firma, y una orden con notas.
        DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'user_id' => $this->a['technician']->id,
            'assignment_type' => 'maintenance', 'performed' => true, 'created_at' => '2026-10-03 10:00', 'description' => 'Detalle interno del remito', 'signature_name' => 'Firma Privada']);
        DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'user_id' => $this->a['technician']->id,
            'assignment_type' => 'inspection', 'performed' => false, 'created_at' => '2026-10-05 10:00']);
        WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'type' => 'claim', 'status' => 'pending', 'notes' => 'Vecino del 4B se quejó']);

        $page = $this->generate();
        $this->assertSame(PublicQrService::TOKEN_LENGTH, strlen($page->token));
        // El enlace es solo el token aleatorio: sin id, empresa ni edificio que permitan recorrer otros.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $page->token);
        $this->assertSame(url('/qr/'.$page->token), $page->publicUrl());
        $this->assertNotSame($page->token, app(PublicQrService::class)->generate($this->a['admin'], $this->a['building'])->token);
        $page->refresh();

        $response = $this->visit($page->token)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Ascensores Norte')->assertSee('Torre Libertad')->assertSee('Av. Libertador 1200')
            ->assertSee('Último mantenimiento registrado')->assertSee('03/10/2026')
            ->assertSee('Trabajo realizado')->assertSee('No es una evaluación ni una certificación de seguridad')
            // La inspección «no realizada» no cuenta como inspección registrada.
            ->assertSee('Última inspección registrada')->assertSee('Sin registros');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        foreach (['Consorcio Confidencial', '11-9999-9999', 'Sra. Privada', '11-5555-0000', 'Nota interna secreta', 'Detalle interno del remito', 'Firma Privada',
            $this->a['technician']->name, 'Vecino del 4B', '011 4000-1000', 'contacto@norte.test'] as $private) {
            $response->assertDontSee($private, false);
        }
        // Solo lectura: sin formularios ni enlaces al portal o al panel.
        foreach (['<form', '/login', '/admin', '/portal', 'wire:'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
        $this->post('/qr/'.$page->token)->assertStatus(405);
    }

    public function test_each_section_is_published_only_when_chosen(): void
    {
        $page = $this->generate();
        app(PublicQrService::class)->setElevatorStatus($this->a['admin'], $this->elevator, 'under_maintenance');
        WorkOrder::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->building->id, 'type' => 'claim', 'status' => 'in_progress', 'notes' => 'Nota de la orden']);
        DB::table('parachute_tests')->insert(['company_id' => $this->a['company']->id, 'elevator_id' => $this->elevator->id, 'building_id' => $this->building->id, 'performed_on' => '2026-09-01',
            'result' => ParachuteTest::UNSATISFACTORY, 'inspector_name' => 'Ing. Privado', 'observations' => 'Detalle técnico interno', 'created_at' => now(), 'updated_at' => now()]);

        // Por defecto: ni estado, ni pruebas, ni trabajos pendientes, ni contacto.
        $this->visit($page->token)->assertOk()->assertSee($this->elevator->label)
            ->assertDontSee('En mantenimiento o reparación')->assertDontSee('paracaídas')->assertDontSee('Trabajos pendientes')->assertDontSee('011 4000-1000');

        $this->inPanel($this->a['admin']);
        app(PublicQrService::class)->updateSections($this->a['admin'], $page, ['show_status' => true, 'show_safety_tests' => true, 'show_pending_work' => true, 'show_contact' => true, 'show_last_maintenance' => false, 'token' => 'x', 'company_id' => 999]);
        $this->assertSame($this->a['company']->id, (int) $page->fresh()->company_id); // claves desconocidas, ignoradas

        $this->visit($page->fresh()->token)->assertOk()
            ->assertSee('Estado informado por la empresa')->assertSee('En mantenimiento o reparación')->assertSee('Información administrativa')
            ->assertSee('Observación pendiente')->assertSee('no fue satisfactoria')
            ->assertSee('Trabajos pendientes')->assertSee('Reclamo: 1 en progreso')
            ->assertSee('011 4000-1000')->assertSee('contacto@norte.test')
            ->assertDontSee('Último mantenimiento registrado')
            ->assertDontSee('Ing. Privado')->assertDontSee('Detalle técnico interno')->assertDontSee('Nota de la orden');
    }

    public function test_regenerating_or_deactivating_kills_the_previous_link(): void
    {
        $first = $this->generate();
        $old = $first->token;
        $this->visit($old)->assertOk();

        $this->inPanel($this->a['admin']);
        $second = app(PublicQrService::class)->generate($this->a['admin'], $this->building);
        $this->assertNotSame($old, $second->token);
        $this->assertSame(1, BuildingPublicPage::withoutGlobalScopes()->count()); // uno por edificio
        $this->visit($old)->assertNotFound()->assertSee('Este código no está disponible');
        $this->visit($second->token)->assertOk();

        $this->inPanel($this->a['admin']);
        $current = $second->token;
        app(PublicQrService::class)->deactivate($this->a['admin'], $second->fresh());
        $this->visit($current)->assertNotFound();

        // Volver a generar no revive ninguno de los anteriores.
        $this->inPanel($this->a['admin']);
        $third = app(PublicQrService::class)->generate($this->a['admin'], $this->building);
        $this->visit($old)->assertNotFound();
        $this->visit($current)->assertNotFound();
        $this->visit($third->token)->assertOk();

        // El token nunca queda en el registro de actividad.
        $this->assertGreaterThan(0, ActivityLog::where('subject_type', 'BuildingPublicPage')->count());
        foreach ([$old, $current, $third->token] as $token) {
            $this->assertFalse(ActivityLog::query()->get()->contains(fn ($log) => str_contains(json_encode($log->changes), $token)));
        }
    }

    public function test_unknown_sequential_or_unavailable_codes_reveal_nothing(): void
    {
        $page = $this->generate();
        $unknown = $this->visit(str_repeat('a', PublicQrService::TOKEN_LENGTH))->assertNotFound()->getContent();
        $this->assertSame($unknown, $this->visit('1')->assertNotFound()->getContent());
        $this->assertSame($unknown, $this->visit((string) $page->id)->assertNotFound()->getContent());
        $this->get('/qr/'.$page->token.'.json')->assertNotFound();

        // Edificio desactivado o empresa sin acceso: el QR deja de mostrar información.
        $this->building->delete();
        $this->assertSame($unknown, $this->visit($page->token)->assertNotFound()->getContent());
        $this->building->restore();
        $this->visit($page->token)->assertOk();
        $this->a['company']->forceFill(['is_active' => false])->save();
        $this->visit($page->token)->assertNotFound();
    }

    public function test_user_text_is_escaped_on_the_public_page(): void
    {
        $this->building->update(['name' => '<script>alert(1)</script>Torre']);
        $this->elevator->forceFill(['label' => '<img src=x onerror=alert(2)>'])->save();
        $page = $this->generate();

        $this->visit($page->token)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;Torre', false);
    }

    public function test_requests_are_rate_limited(): void
    {
        $page = $this->generate();
        auth()->logout();

        for ($i = 0; $i < 60; $i++) {
            $this->get('/qr/'.$page->token)->assertOk();
        }
        $this->get('/qr/'.$page->token)->assertStatus(429);
        $this->get('/qr/'.str_repeat('b', PublicQrService::TOKEN_LENGTH))->assertStatus(429); // también los que prueban tokens
    }

    public function test_the_qr_image_downloads_encode_only_the_public_link(): void
    {
        $page = $this->generate();
        $this->inPanel($this->a['admin']);

        $svg = $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'svg']))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());
        $png = $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'png']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $png->getContent());
        $this->get('/qr-codes/'.$this->building->id.'/download/pdf')->assertNotFound();

        // Desactivado: no hay imagen que descargar.
        app(PublicQrService::class)->deactivate($this->a['admin'], $page);
        $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'svg']))->assertNotFound();
    }

    public function test_staff_need_the_public_pages_area_and_manage_to_change_anything(): void
    {
        $page = $this->generate();

        // Sin el área: ni la pantalla, ni la imagen, ni el servicio.
        $none = $this->staff(['buildings' => 'manage'], 'Sin QR');
        $this->assertFalse(PublicQrPage::canAccess());
        $this->get(PublicQrPage::getUrl())->assertForbidden();
        $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'svg']))->assertNotFound();
        $this->denied(fn () => app(PublicQrService::class)->generate($none, $this->building), 403);

        // Con «ver»: la pantalla, la vista previa y la descarga; nada que cambie algo.
        $viewer = $this->staff(['public_pages' => 'view'], 'Mira QR');
        $this->get(PublicQrPage::getUrl(['edificio' => $this->building->id]))->assertOk()->assertSee('Vista previa')->assertSee('Torre Libertad');
        $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'svg']))->assertOk();
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])
            ->assertActionHidden('regenerate')->assertActionHidden('deactivate')->assertActionHidden('saveSettings');
        $before = $page->fresh()->only(['token', 'is_active', 'show_contact']);
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])->set('sections.show_contact', true)
            ->call('mountAction', 'saveSettings')->call('callMountedAction');
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])->call('mountAction', 'deactivate')->call('callMountedAction');
        $this->assertSame($before, $page->fresh()->only(['token', 'is_active', 'show_contact']));
        foreach ([fn () => app(PublicQrService::class)->generate($viewer, $this->building), fn () => app(PublicQrService::class)->deactivate($viewer, $page->fresh()),
            fn () => app(PublicQrService::class)->updateSections($viewer, $page->fresh(), ['show_contact' => true]), fn () => app(PublicQrService::class)->setElevatorStatus($viewer, $this->elevator, 'out_of_service')] as $call) {
            $this->denied($call, 403);
        }

        // Con «gestionar»: genera, regenera, elige qué se publica y el estado de cada ascensor.
        $this->staff(['public_pages' => 'manage'], 'Gestiona QR');
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])
            ->set('sections.show_contact', true)->set("statuses.{$this->elevator->id}", 'out_of_service')
            ->callAction('saveSettings')->assertHasNoActionErrors();
        $this->assertTrue($page->fresh()->show_contact);
        $this->assertSame('out_of_service', $this->elevator->fresh()->public_status);
        $old = $page->fresh()->token;
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])
            ->assertActionHidden('generate')->callAction('regenerate')->assertHasNoActionErrors();
        $this->assertNotSame($old, $page->fresh()->token);
        // Desactivado, se puede generar uno nuevo (sin revivir el anterior).
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])->callAction('deactivate');
        $this->assertFalse($page->fresh()->is_active);
        Livewire::test(PublicQrPage::class, ['buildingId' => $this->building->id])->assertActionHidden('regenerate')->assertActionHidden('deactivate')->callAction('generate');
        $this->assertTrue($page->fresh()->is_active);
    }

    public function test_nothing_crosses_companies(): void
    {
        $page = $this->generate(['show_pending_work' => true]);
        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        // Un trabajo de B en un edificio de B no aparece en el QR de A.
        WorkOrder::factory()->create(['company_id' => $b['company']->id, 'building_id' => $b['building']->id, 'type' => 'installation', 'status' => 'pending']);

        $this->inPanel($b['admin']);
        $this->get(PublicQrPage::getUrl(['edificio' => $this->building->id]))->assertNotFound();
        $this->get(route('public-qr.download', ['building' => $this->building->id, 'format' => 'svg']))->assertNotFound();
        $this->denied(fn () => app(PublicQrService::class)->generate($b['admin'], $this->building), 404);
        $this->denied(fn () => app(PublicQrService::class)->deactivate($b['admin'], $page), 404);
        $this->denied(fn () => app(PublicQrService::class)->setElevatorStatus($b['admin'], $this->elevator, 'out_of_service'), 404);
        // La pantalla de B, aun con datos manipulados, no guarda estados de ascensores de A.
        $bPage = app(PublicQrService::class)->generate($b['admin'], $b['building']);
        Livewire::test(PublicQrPage::class, ['buildingId' => $b['building']->id])->set("statuses.{$this->elevator->id}", 'out_of_service')->callAction('saveSettings');
        $this->assertNull($this->elevator->fresh()->public_status);

        $this->visit($page->fresh()->token)->assertOk()->assertDontSee('Instalación')->assertDontSee($b['building']->name);
        $this->visit($bPage->token)->assertOk()->assertDontSee('Torre Libertad');
    }
}
