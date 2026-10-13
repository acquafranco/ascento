<?php

namespace Tests\Feature\Access;

use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\App\TaskNotification;
use App\Services\Staff\StaffManager;
use App\Services\Tasks\TaskService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Destinatarios elegidos para el recordatorio de una tarea: solo personas
 * activas y autorizadas de la empresa, verificadas otra vez al avisar; un
 * aviso por tarea, destinatario y recordatorio; vencimiento y recordatorio
 * separados; cambios de fecha, estado y destinatarios; campanita y push; y
 * nada entre empresas.
 */
class TaskReminderRecipientsTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['webpush.vapid.public_key' => null]); // sin push salvo en la prueba que lo pide
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name, ?array $tenant = null): User
    {
        $tenant ??= $this->a;
        $this->actingAs($tenant['admin']);

        return app(StaffManager::class)->create($tenant['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    private function task(array $recipients, array $extra = []): Task
    {
        $this->inPanel($this->a['admin']);

        return app(TaskService::class)->create($this->a['admin'], ['title' => 'Llamar al consorcio', 'priority' => 'medium', 'due_at' => '2026-10-20 18:00', 'remind_at' => '2026-10-15 10:00', 'reminder_recipients' => $recipients] + $extra);
    }

    /** @return array<int, int> destinatario => avisos de recordatorio de esa tarea */
    private function reminders(Task $task): array
    {
        return DB::table('notifications')->where('type', TaskNotification::class)->where('dedupe_key', 'like', 'task:'.$task->id.':reminder:%')
            ->get()->countBy('notifiable_id')->mapWithKeys(fn ($n, $id) => [(int) $id => $n])->sortKeys()->all();
    }

    private function remindAt(string $when): void
    {
        auth()->logout();
        $this->travelTo(Carbon::parse($when));
        $this->artisan('tasks:remind')->assertSuccessful();
    }

    private function invalid(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Debía rechazar los destinatarios.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reminder_recipients', $e->errors());
        }
    }

    public function test_the_creator_chooses_who_gets_the_reminder_and_it_arrives_once(): void
    {
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $beto = $this->staff(['tasks' => 'manage'], 'Beto');
        $this->inPanel($this->a['admin']);

        // Desde el formulario: el dueño y dos administrativos con acceso a tareas.
        Livewire::test(CreateTask::class)->fillForm([
            'title' => 'Renovación del contrato', 'priority' => 'high', 'due_at' => '2026-10-20 18:00', 'remind_at' => '2026-10-15 10:00',
            'reminder_recipients' => [$ana->id, $beto->id], 'assigned_to' => $this->a['admin']->id,
        ])->call('create')->assertHasNoFormErrors();
        $task = Task::sole();
        $this->assertEqualsCanonicalizing([$ana->id, $beto->id], $task->reminderRecipients()->pluck('users.id')->all());
        $this->assertSame([(int) $this->a['company']->id], DB::table('task_reminder_recipients')->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])->assertFormSet(['reminder_recipients' => [$ana->id, $beto->id]]);

        // Antes del recordatorio, nada (el vencimiento es otra cosa).
        $this->remindAt('2026-10-15 09:55');
        $this->assertSame([], $this->reminders($task));

        // A la hora: a los elegidos (no al responsable, que no se eligió), una vez.
        $this->remindAt('2026-10-15 10:00');
        $this->assertSame([$ana->id => 1, $beto->id => 1], $this->reminders($task));
        $this->remindAt('2026-10-15 10:05');
        $this->remindAt('2026-10-16 10:00');
        $this->assertSame([$ana->id => 1, $beto->id => 1], $this->reminders($task)); // sin duplicados

        // Cada uno abre la tarea donde puede: Beto (gestiona) en la tarea; Ana (ve) en «Mis tareas», donde figura.
        $data = fn (User $u) => json_decode(DB::table('notifications')->where('notifiable_id', $u->id)->where('type', TaskNotification::class)->value('data'), true);
        $this->assertSame(parse_url(TaskResource::getUrl('edit', ['record' => $task]), PHP_URL_PATH), $data($beto)['path']);
        $this->assertStringEndsWith('?tab=mine', $data($ana)['path']);
        $this->inPanel($beto);
        $this->get($data($beto)['path'])->assertOk();
        $this->inPanel($ana);
        $this->get($data($ana)['path'])->assertOk();
        Livewire::test(ListTasks::class)->set('activeTab', 'mine')->assertCanSeeTableRecords([$task]);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $ana->id)->whereNull('read_at')->count()); // se marca leída desde la campanita, como cualquiera
    }

    public function test_without_a_choice_it_works_as_before(): void
    {
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $assigned = $this->task([], ['assigned_to' => $ana->id]);
        $own = $this->task([]);

        $this->remindAt('2026-10-15 10:00');
        $this->assertSame([$ana->id => 1], $this->reminders($assigned));
        $this->assertSame([$this->a['admin']->id => 1], $this->reminders($own));
        // Sin el campo en el pedido (otra pantalla), los elegidos no se tocan.
        $task = $this->task([$ana->id]);
        app(TaskService::class)->update($this->a['admin'], $task, ['title' => 'Otro título']);
        $this->assertSame([$ana->id], $task->reminderRecipients()->pluck('users.id')->all());
    }

    public function test_only_active_authorized_people_of_the_company_can_be_chosen(): void
    {
        $noTasks = $this->staff(['clients' => 'manage'], 'Sin tareas');
        $gone = $this->staff(['tasks' => 'view'], 'Desactivada');
        $gone->delete();
        auth()->logout();
        $b = $this->makeTenant();
        $foreign = $this->staff(['tasks' => 'manage'], 'Ajena', $b);

        foreach ([[$this->a['technician']->id], [$noTasks->id], [$gone->id], [$foreign->id], [$b['admin']->id], [999999]] as $ids) {
            $this->invalid(fn () => $this->task($ids));
        }
        $this->invalid(fn () => $this->task(range(1, TaskService::MAX_RECIPIENTS + 1)));
        $this->assertSame(0, Task::count());

        // El selector ofrece solo a los que pueden.
        $options = app(TaskService::class)->assignableUsers($this->a['company']->id);
        $this->assertArrayHasKey($this->a['admin']->id, $options);
        foreach ([$this->a['technician'], $noTasks, $gone, $foreign] as $user) {
            $this->assertArrayNotHasKey($user->id, $options);
        }
    }

    public function test_people_who_lose_access_stop_receiving_it_without_falling_back_to_others(): void
    {
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $beto = $this->staff(['tasks' => 'view'], 'Beto');
        $carla = $this->staff(['tasks' => 'view'], 'Carla');
        $task = $this->task([$ana->id, $beto->id, $carla->id], ['assigned_to' => $this->a['admin']->id]);

        // Antes del aviso: Ana pierde tareas, Beto es desactivado; Carla sigue.
        $this->inPanel($this->a['admin']);
        app(StaffManager::class)->setPermissions($this->a['admin'], $ana, ['tasks' => 'none']);
        $beto->delete();
        $this->remindAt('2026-10-15 10:00');
        $this->assertSame([$carla->id => 1], $this->reminders($task));

        // Si ninguno de los elegidos puede, no se avisa a otro en su lugar (ni al responsable).
        $other = $this->task([$carla->id], ['assigned_to' => $this->a['admin']->id, 'remind_at' => '2026-10-16 10:00']);
        $this->a['company']->forgetPlan();
        DB::table('users')->where('id', $carla->id)->update(['company_id' => $this->makeTenant()['company']->id]); // ya no es de la empresa
        $this->remindAt('2026-10-16 10:00');
        $this->assertSame([], $this->reminders($other));
    }

    public function test_date_status_and_recipient_changes_update_the_reminder(): void
    {
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $beto = $this->staff(['tasks' => 'view'], 'Beto');
        $task = $this->task([$ana->id]);

        // Se posterga antes de avisar: a la hora vieja, nada; a la nueva, sí.
        app(TaskService::class)->update($this->a['admin'], $task, ['remind_at' => '2026-10-16 10:00']);
        $this->remindAt('2026-10-15 10:00');
        $this->assertSame([], $this->reminders($task));

        // Cambian los destinatarios antes de avisar: recibe el nuevo, no el anterior.
        $this->inPanel($this->a['admin']);
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['reminder_recipients' => [$beto->id]]);
        $this->remindAt('2026-10-16 10:00');
        $this->assertSame([$beto->id => 1], $this->reminders($task));

        // Cambiarlos DESPUÉS de avisar no reenvía ese recordatorio.
        $this->inPanel($this->a['admin']);
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['reminder_recipients' => [$ana->id, $beto->id]]);
        $this->remindAt('2026-10-16 10:10');
        $this->assertSame([$beto->id => 1], $this->reminders($task));

        // Mover solo el vencimiento no reprograma el recordatorio.
        $this->inPanel($this->a['admin']);
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['due_at' => '2026-10-25 18:00']);
        $this->assertNotNull($task->fresh()->reminded_at);

        // Un recordatorio nuevo vuelve a avisar, a los elegidos de ese momento, una vez.
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['remind_at' => '2026-10-20 09:00']);
        $this->remindAt('2026-10-20 09:00');
        $this->remindAt('2026-10-20 09:05');
        $this->assertSame([$ana->id => 1, $beto->id => 2], $this->reminders($task));

        // Cancelada o completada: no avisa.
        $cancelled = $this->task([$ana->id], ['remind_at' => '2026-10-21 10:00', 'due_at' => '2026-10-22 10:00']);
        app(TaskService::class)->setStatus($this->a['admin'], $cancelled, 'cancelled');
        $done = $this->task([$ana->id], ['remind_at' => '2026-10-21 10:00', 'due_at' => '2026-10-22 10:00']);
        app(TaskService::class)->setStatus($this->a['admin'], $done, 'done');
        $this->remindAt('2026-10-21 10:00');
        $this->assertSame([], $this->reminders($cancelled));
        $this->assertSame([], $this->reminders($done));

        // Quién recibe el recordatorio queda en la actividad.
        $this->assertSame(3, ActivityLog::where('action', 'task.reminder_recipients_updated')->where('subject_id', $task->id)->count());
    }

    public function test_reminders_reach_the_bell_and_push_and_never_cross_companies(): void
    {
        $this->configureVapid();
        $this->fakePushService();
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $this->subscribeDevice($ana);
        $task = $this->task([$ana->id]);

        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');
        $this->subscribeDevice($b['admin']);
        $this->inPanel($b['admin']);
        $bTask = app(TaskService::class)->create($b['admin'], ['title' => 'De B', 'priority' => 'low', 'remind_at' => '2026-10-15 10:00']);
        // B no puede elegir a nadie de A ni tocar la tarea de A.
        $this->invalid(fn () => app(TaskService::class)->create($b['admin'], ['title' => 'X', 'priority' => 'low', 'reminder_recipients' => [$ana->id]]));
        try {
            app(TaskService::class)->update($b['admin'], $task, ['reminder_recipients' => [$b['admin']->id]]);
            $this->fail('Otra empresa no edita la tarea.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->remindAt('2026-10-15 10:00');
        // Campanita: cada uno el suyo; push: a los dos dispositivos, uno por persona.
        $this->assertSame([$ana->id => 1], $this->reminders($task));
        $this->assertSame([$b['admin']->id => 1], $this->reminders($bTask));
        $this->assertCount(2, $this->deliveredEndpoints());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $ana->id)->where('company_id', $b['company']->id)->count());
    }
}
