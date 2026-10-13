<?php

namespace Tests\Feature\Stage7;

use App\Filament\Pages\ParachuteTestsPage;
use App\Filament\Resources\ParachuteTests\Pages\CreateParachuteTest;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\ParachuteSchedule;
use App\Models\ParachuteTest;
use App\Models\User;
use App\Notifications\App\ParachuteReminderNotification;
use App\Services\Parachute\ParachuteService;
use App\Services\Staff\StaffManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 7.3 y 7.4: pruebas de paracaídas (periodicidad por equipo o por
 * cliente, prevista / realizada / no satisfactoria, archivos privados) y
 * recordatorios (sin duplicados, solo a quien corresponde, nunca como
 * prueba hecha). Permisos en el servidor y nada entre empresas.
 */
class ParachuteTestsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Elevator $elevator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        config(['webpush.vapid.public_key' => null]); // sin push reales en las pruebas
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->elevator = $this->elevatorOf($this->a);
    }

    private function elevatorOf(array $tenant, string $label = 'Equipo P1'): Elevator
    {
        $elevator = (new Elevator)->forceFill(['building_id' => $tenant['building']->id, 'company_id' => $tenant['company']->id, 'label' => $label, 'kind' => 'elevator', 'is_active' => true]);
        $elevator->save();

        return $elevator;
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

    private function service(): ParachuteService
    {
        return app(ParachuteService::class);
    }

    private function record(string $date, string $result = ParachuteTest::SATISFACTORY, ?Elevator $elevator = null, array $extra = []): ParachuteTest
    {
        $this->inPanel($this->a['admin']);

        return $this->service()->record($this->a['admin'], ['elevator_id' => ($elevator ?? $this->elevator)->id, 'performed_on' => $date, 'result' => $result, 'performed_by_id' => $this->a['technician']->id] + $extra);
    }

    private function statusOf(?Elevator $elevator = null): ?array
    {
        return $this->service()->overview($this->a['company']->id)->first(fn ($row) => $row['elevator']->id === ($elevator ?? $this->elevator)->id);
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

    private function invalid(\Closure $call, string $field): void
    {
        try {
            $call();
            $this->fail("Debía rechazar {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_status_distinguishes_planned_done_failed_and_never_assumes_a_period(): void
    {
        // Sin periodicidad: no se inventa una (ni próxima fecha).
        $this->assertSame([ParachuteService::UNCONFIGURED, null], [$this->statusOf()['status'], $this->statusOf()['next_due']]);

        // Política del cliente: se aplica a sus equipos sin una propia.
        $this->inPanel($this->a['admin']);
        $this->service()->setClientInterval($this->a['admin'], Client::find($this->a['building']->client_id), 12);
        $this->assertSame([ParachuteService::NO_RECORD, 12, 'client'], [$this->statusOf()['status'], $this->statusOf()['interval'], $this->statusOf()['interval_source']]);

        // Realizada y satisfactoria: próxima prevista = última + periodicidad.
        $this->record('2026-03-01');
        $row = $this->statusOf();
        $this->assertSame([ParachuteService::OK, '2027-03-01'], [$row['status'], $row['next_due']->toDateString()]);

        // El equipo pisa al cliente; próxima dentro del aviso (30 días por defecto): próxima.
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 8, 'notice_days' => 30]);
        $row = $this->statusOf();
        $this->assertSame([ParachuteService::UPCOMING, '2026-11-01', 'elevator'], [$row['status'], $row['next_due']->toDateString(), $row['interval_source']]);

        // Vencida.
        $this->travelTo(Carbon::parse('2026-11-02 09:00'));
        $this->assertSame(ParachuteService::OVERDUE, $this->statusOf()['status']);

        // No satisfactoria: lo es aunque la periodicidad diga otra cosa, y no mueve la próxima fecha.
        $this->record('2026-11-02', ParachuteTest::UNSATISFACTORY);
        $row = $this->statusOf();
        $this->assertSame([ParachuteService::FAILED, '2026-11-01'], [$row['status'], $row['next_due']->toDateString()]);

        // Una satisfactoria posterior la resuelve.
        $this->record('2026-11-02');
        $this->assertSame([ParachuteService::OK, '2027-07-02'], [$this->statusOf()['status'], $this->statusOf()['next_due']->toDateString()]);

        // Los inactivos no aparecen.
        $this->elevator->forceFill(['is_active' => false])->save();
        $this->assertNull($this->statusOf());
    }

    public function test_recording_validates_dates_results_people_and_the_elevator(): void
    {
        $this->inPanel($this->a['admin']);
        $base = ['elevator_id' => $this->elevator->id, 'performed_on' => '2026-10-10', 'result' => ParachuteTest::SATISFACTORY, 'performed_by_name' => 'Juan Externo'];

        $this->invalid(fn () => $this->service()->record($this->a['admin'], ['performed_on' => '2026-10-20'] + $base), 'performed_on'); // futura: es la prevista, no una realizada
        $this->invalid(fn () => $this->service()->record($this->a['admin'], ['performed_on' => ''] + $base), 'performed_on');
        $this->invalid(fn () => $this->service()->record($this->a['admin'], ['result' => 'pending'] + $base), 'result');
        $this->invalid(fn () => $this->service()->record($this->a['admin'], ['performed_by_name' => ''] + $base), 'performed_by_id');
        $this->invalid(fn () => $this->service()->record($this->a['admin'], ['elevator_id' => 0] + $base), 'elevator_id');
        $this->invalid(fn () => $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 0]), 'interval_months');
        $this->invalid(fn () => $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 6, 'notice_days' => 400]), 'notice_days');

        $test = $this->service()->record($this->a['admin'], $base + ['inspector_name' => 'Ing. Gómez', 'inspector_registration' => 'MAT-123', 'observations' => 'Sin novedades']);
        $this->assertSame([$this->a['company']->id, $this->a['building']->id, 'Juan Externo', 'Ing. Gómez', $this->a['admin']->id],
            [(int) $test->company_id, (int) $test->building_id, $test->performerName(), $test->inspector_name, (int) $test->recorded_by]);

        // Desde el panel también (con el ascensor elegido en la URL).
        Livewire::test(CreateParachuteTest::class, ['elevator' => $this->elevator->id])
            ->assertFormSet(['elevator_id' => $this->elevator->id])
            ->fillForm(['result' => ParachuteTest::UNSATISFACTORY, 'performed_by_id' => $this->a['technician']->id, 'observations' => 'Patín gastado'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(2, ParachuteTest::count());

        // No se borran: historial.
        $this->assertFalse(ParachuteTestResource::canDelete($test));
    }

    public function test_files_are_private_and_checked_by_content(): void
    {
        $test = $this->record('2026-10-10');
        $pdf = UploadedFile::fake()->createWithContent('acta.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $file = $this->service()->attach($this->a['admin'], $test, $pdf);
        $this->assertStringStartsWith('elevators/'.$this->a['company']->id.'/parachute/', $file->path);
        $this->assertTrue($file->hasSafePath());
        $this->invalid(fn () => $this->service()->attach($this->a['admin'], $test, UploadedFile::fake()->createWithContent('acta.pdf', '<?php echo 1;')), 'files');

        $this->get($file->url())->assertOk();

        // Sin el área de inspecciones, otro rol u otra empresa: 404.
        $this->staff(['buildings' => 'view'], 'Sin inspecciones');
        $this->get($file->url())->assertNotFound();
        $this->inPanel($this->a['technician']);
        $this->get($file->url())->assertNotFound(); // el técnico no ve los archivos del panel
        $this->staff(['inspections' => 'view'], 'Mira inspecciones');
        $this->get($file->url())->assertOk();

        auth()->logout();
        $b = $this->makeTenant();
        $this->inPanel($b['admin']);
        $this->get($file->url())->assertNotFound();
    }

    public function test_staff_need_inspections_and_manage_to_change_anything(): void
    {
        $test = $this->record('2026-10-10');

        $none = $this->staff(['buildings' => 'manage'], 'Sin inspecciones');
        $this->assertFalse(ParachuteTestsPage::canAccess());
        $this->get(ParachuteTestsPage::getUrl())->assertForbidden();
        $this->get(ParachuteTestResource::getUrl('index'))->assertForbidden();

        $viewer = $this->staff(['inspections' => 'view'], 'Mira');
        $this->get(ParachuteTestsPage::getUrl())->assertOk()->assertSee('Equipo P1');
        $this->get(ParachuteTestResource::getUrl('view', ['record' => $test]))->assertOk();
        $this->assertFalse(ParachuteTestResource::canCreate());
        $this->get(ParachuteTestResource::getUrl('create'))->assertForbidden();
        Livewire::test(ParachuteTestsPage::class)->assertActionHidden('configure', ['elevator' => $this->elevator->id])->assertActionHidden('clientPolicy');
        Livewire::test(ParachuteTestsPage::class)->call('mountAction', 'configure', ['elevator' => $this->elevator->id])
            ->set('mountedActions.0.data.interval_months', 3)->call('callMountedAction');
        $this->assertNull(ParachuteSchedule::first());
        foreach ([fn () => $this->service()->record($viewer, ['elevator_id' => $this->elevator->id, 'performed_on' => '2026-10-10', 'result' => 'satisfactory', 'performed_by_name' => 'X']),
            fn () => $this->service()->update($viewer, $test, ['performed_on' => '2026-10-01', 'result' => 'unsatisfactory', 'performed_by_name' => 'X']),
            fn () => $this->service()->configure($viewer, $this->elevator, ['interval_months' => 3]),
            fn () => $this->service()->setClientInterval($viewer, Client::find($this->a['building']->client_id), 3)] as $call) {
            $this->denied($call, 403);
        }

        $manager = $this->staff(['inspections' => 'manage'], 'Gestiona');
        Livewire::test(ParachuteTestsPage::class)->callAction('configure', ['interval_months' => 6, 'notice_days' => 15, 'responsible_id' => $manager->id], ['elevator' => $this->elevator->id])
            ->assertHasNoActionErrors();
        $this->assertSame([6, 15, $manager->id], [ParachuteSchedule::first()->interval_months, ParachuteSchedule::first()->notice_days, ParachuteSchedule::first()->responsible_id]);

        // Un responsable tiene que poder intervenir: un administrativo sin inspecciones, no.
        $this->invalid(fn () => $this->service()->configure($manager, $this->elevator, ['interval_months' => 6, 'responsible_id' => $none->id]), 'responsible_id');
    }

    public function test_reminders_go_once_to_the_right_people_and_never_count_as_a_test(): void
    {
        $this->record('2025-11-01');
        $inspector = $this->a['technician'];
        $responsible = $this->staff(['inspections' => 'view'], 'Responsable');
        $this->inPanel($this->a['admin']);
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 12, 'notice_days' => 30, 'inspector_id' => $inspector->id, 'responsible_id' => $responsible->id]);
        auth()->logout();

        // Próxima (vence el 01/11/2026, dentro de 30 días): dueño, inspector y responsable, una vez.
        $this->artisan('parachute:remind')->assertSuccessful();
        $rows = DB::table('notifications')->where('type', ParachuteReminderNotification::class)->get();
        $this->assertEqualsCanonicalizing([$this->a['admin']->id, $inspector->id, $responsible->id], $rows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringContainsString('Todavía no hay una prueba registrada', json_encode(json_decode($rows->first()->data, true), JSON_UNESCAPED_UNICODE));
        $this->artisan('parachute:remind');
        $this->assertSame(3, DB::table('notifications')->where('type', ParachuteReminderNotification::class)->count()); // sin duplicados
        $this->assertSame(1, ParachuteTest::count()); // un aviso no es una prueba

        // Estado del aviso: enviado a 3, leído por 1.
        DB::table('notifications')->where('notifiable_id', $this->a['admin']->id)->update(['read_at' => now()]);
        $this->assertSame(['sent' => 3, 'read' => 1], array_intersect_key($this->service()->reminderSummary($this->a['company']->id)->get($this->elevator->id), ['sent' => 1, 'read' => 1]));

        // Vencida: un aviso nuevo (otro motivo), una vez.
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $this->artisan('parachute:remind');
        $this->artisan('parachute:remind');
        $this->assertSame(6, DB::table('notifications')->where('type', ParachuteReminderNotification::class)->count());

        // Si el responsable pierde acceso a inspecciones, deja de recibirlos; con una prueba nueva, nada que avisar.
        $this->inPanel($this->a['admin']);
        app(StaffManager::class)->setPermissions($this->a['admin'], $responsible, ['inspections' => 'none']);
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 1, 'notice_days' => 30, 'inspector_id' => $inspector->id]);
        auth()->logout();
        $this->artisan('parachute:remind');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $responsible->id)->where('dedupe_key', 'like', '%2025-12-01')->count());
        $this->record('2026-11-03');
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 12, 'notice_days' => 30, 'inspector_id' => $inspector->id]);
        $this->assertSame(ParachuteService::OK, $this->statusOf()['status']);
        auth()->logout();
        $before = DB::table('notifications')->count();
        $this->artisan('parachute:remind');
        $this->assertSame($before, DB::table('notifications')->count());
    }

    public function test_reminders_and_data_never_cross_companies(): void
    {
        $this->record('2025-01-01');
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 12]);
        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');

        $this->artisan('parachute:remind');
        $this->assertSame(0, DB::table('notifications')->whereIn('notifiable_id', [$b['admin']->id, $b['technician']->id])->count());

        // B no ve, no registra ni configura sobre equipos de A, ni los elige como responsables.
        $this->inPanel($b['admin']);
        $this->assertNotContains($this->elevator->id, app(ParachuteService::class)->overview($b['company']->id)->pluck('elevator.id')->all());
        $this->assertFalse(ParachuteTestResource::getEloquentQuery()->exists());
        $this->invalid(fn () => $this->service()->record($b['admin'], ['elevator_id' => $this->elevator->id, 'performed_on' => '2026-10-10', 'result' => 'satisfactory', 'performed_by_name' => 'X']), 'elevator_id');
        $this->denied(fn () => $this->service()->configure($b['admin'], $this->elevator, ['interval_months' => 3]), 404);
        $this->denied(fn () => $this->service()->update($b['admin'], ParachuteTest::withoutGlobalScopes()->first(), ['performed_on' => '2026-10-01', 'result' => 'unsatisfactory', 'performed_by_name' => 'X']), 404);
        $bElevator = $this->elevatorOf($b);
        $this->invalid(fn () => $this->service()->configure($b['admin'], $bElevator, ['interval_months' => 3, 'inspector_id' => $this->a['technician']->id]), 'inspector_id');
        Livewire::test(ParachuteTestsPage::class)->call('mountAction', 'configure', ['elevator' => $this->elevator->id]);
        $this->assertNull(ParachuteSchedule::withoutGlobalScopes()->where('company_id', $b['company']->id)->first());

        // Empresa sin acceso vigente: no se avisa.
        $this->a['company']->forceFill(['is_active' => false])->save();
        auth()->logout();
        DB::table('notifications')->delete();
        $this->artisan('parachute:remind');
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_changes_of_dates_and_responsibles_are_logged(): void
    {
        $test = $this->record('2026-10-01');
        $this->service()->update($this->a['admin'], $test, ['performed_on' => '2026-10-02', 'result' => ParachuteTest::SATISFACTORY, 'performed_by_id' => $this->a['technician']->id]);
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 12, 'responsible_id' => $this->a['admin']->id]);
        $this->service()->configure($this->a['admin'], $this->elevator, ['interval_months' => 6, 'responsible_id' => $this->a['technician']->id]);

        $dates = ActivityLog::where('action', 'parachute_test.updated')->sole()->changes;
        $this->assertEquals(['from' => '2026-10-01 00:00', 'to' => '2026-10-02 00:00'], $dates['performed_on']); // MySQL reordena las claves del JSON
        $schedule = ActivityLog::where('action', 'parachute_schedule.updated')->sole()->changes;
        $this->assertEquals(['interval_months' => ['from' => 12, 'to' => 6], 'responsible_id' => ['from' => $this->a['admin']->id, 'to' => $this->a['technician']->id]], $schedule);
        $this->assertSame(1, ActivityLog::where('action', 'parachute_test.created')->count());
    }
}
