<?php

namespace Tests\Feature\Access;

use App\Filament\RelationManagers\AccountStatementRelationManager;
use App\Filament\RelationManagers\InteractionsRelationManager;
use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\ClientInteractionService;
use App\Services\Insights\AttentionCenter;
use App\Services\Staff\StaffManager;
use App\Services\Tasks\TaskService;
use App\Support\Access\Access;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 6, PR 3: tareas y recordatorios, e historial de contactos. Empresa,
 * responsables, vínculos y permisos verificados en el servidor; estados,
 * vencimientos y recordatorios (una sola vez); el historial persiste.
 */
class TasksAndContactsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::find($this->a['building']->client_id);
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function staff(array $levels, string $name = 'Ana Oficina'): User
    {
        $this->actingAs($this->a['admin']);

        return app(StaffManager::class)->create($this->a['admin'], ['name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123'], $levels);
    }

    private function denied(\Closure $call, int $status = 403): void
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

    private function notificationsOf(User $user): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('type', 'like', '%TaskNotification%')->count();
    }

    public function test_the_owner_creates_and_assigns_a_task_and_the_assignee_is_told(): void
    {
        $ana = $this->staff(['tasks' => 'view']);
        $this->inPanel($this->a['admin']);

        Livewire::test(CreateTask::class)->fillForm([
            'title' => 'Llamar al consorcio por la renovación', 'priority' => 'high', 'assigned_to' => $ana->id,
            'due_at' => '2026-10-16 12:00', 'client_id' => $this->client->id,
        ])->call('create')->assertHasNoFormErrors();

        $task = Task::sole();
        $this->assertSame([$this->a['company']->id, $this->a['admin']->id, $ana->id, 'pending', 'high'], [(int) $task->company_id, (int) $task->created_by, (int) $task->assigned_to, $task->status, $task->priority]);
        $this->assertSame(1, $this->notificationsOf($ana));
        $this->assertSame('owner', ActivityLog::where('action', 'task.created')->sole()->actor_role);

        // Visible como pendiente; en «Mis tareas» de Ana.
        $this->inPanel($ana);
        Livewire::test(ListTasks::class)->set('activeTab', 'mine')->assertCanSeeTableRecords([$task]);
    }

    public function test_assignees_and_links_must_belong_to_the_company_and_have_access(): void
    {
        $this->actingAs($this->a['admin']);
        $service = app(TaskService::class);
        $noTasks = $this->staff(['clients' => 'manage'], 'Sin tareas');
        $this->actingAs($this->a['admin']);

        auth()->logout();
        $b = $this->makeTenant();
        $foreignClient = Client::find($b['building']->client_id);
        $this->actingAs($this->a['admin']);

        foreach ([$this->a['technician'], $noTasks, $b['admin']] as $wrong) {
            $this->invalid(fn () => $service->create($this->a['admin'], ['title' => 'X', 'priority' => 'low', 'assigned_to' => $wrong->id]), 'assigned_to');
        }
        $this->invalid(fn () => $service->create($this->a['admin'], ['title' => 'X', 'priority' => 'low', 'client_id' => $foreignClient->id]), 'client_id');
        $this->invalid(fn () => $service->create($this->a['admin'], ['title' => 'X', 'priority' => 'low', 'building_id' => $b['building']->id]), 'building_id');
        $this->invalid(fn () => $service->create($this->a['admin'], ['title' => 'X', 'priority' => 'nada']), 'priority');
        $this->invalid(fn () => $service->create($this->a['admin'], ['title' => 'X', 'priority' => 'low', 'due_at' => '2026-10-15 10:00', 'remind_at' => '2026-10-16 10:00']), 'remind_at');
        $this->assertSame(0, Task::count());

        // El responsable posible: el dueño y administrativos con tareas.
        $ana = $this->staff(['tasks' => 'view']);
        $this->assertEqualsCanonicalizing([$ana->id, $this->a['admin']->id], array_map('intval', array_keys(app(TaskService::class)->assignableUsers($this->a['company']->id))));
    }

    public function test_status_changes_completion_and_who_may_change_them(): void
    {
        $ana = $this->staff(['tasks' => 'view'], 'Ana');
        $beto = $this->staff(['tasks' => 'view'], 'Beto');
        $this->actingAs($this->a['admin']);
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'Revisar contrato', 'priority' => 'medium', 'assigned_to' => $ana->id]);
        $service = app(TaskService::class);

        // Beto (solo «ver», no es suyo) no la toca; Ana (responsable) la completa.
        $this->actingAs($beto);
        $this->denied(fn () => $service->setStatus($beto, $task->fresh(), 'done'));
        $this->actingAs($ana);
        $this->denied(fn () => $service->setStatus($ana, $task->fresh(), 'cancelled')); // cancelar es de quien gestiona
        $service->setStatus($ana, $task->fresh(), 'done');
        $task->refresh();
        $this->assertSame(['done', $ana->id], [$task->status, (int) $task->completed_by]);
        $this->assertNotNull($task->completed_at);
        $this->assertFalse($task->isOpen());

        // Reabrir limpia quién y cuándo; el dueño cancela.
        $service->setStatus($ana, $task->fresh(), 'pending');
        $this->assertNull($task->fresh()->completed_at);
        $this->actingAs($this->a['admin']);
        $service->setStatus($this->a['admin'], $task->fresh(), 'cancelled');
        $this->assertSame('cancelled', $task->fresh()->status);

        // Con solo «ver», crear y editar no.
        $this->actingAs($ana);
        $this->denied(fn () => $service->create($ana, ['title' => 'Mía', 'priority' => 'low']));
        $this->denied(fn () => $service->update($ana, $task->fresh(), ['title' => 'Cambio']));
        $this->inPanel($ana);
        $this->assertTrue(TaskResource::canAccess());
        $this->assertFalse(TaskResource::canCreate());
        $this->assertFalse(TaskResource::canEdit($task));
        $this->assertFalse(TaskResource::canDelete($task));
    }

    public function test_overdue_tasks_show_up_and_reminders_are_sent_once(): void
    {
        $ana = $this->staff(['tasks' => 'manage'], 'Ana');
        $this->actingAs($this->a['admin']);
        $service = app(TaskService::class);
        $task = $service->create($this->a['admin'], ['title' => 'Mandar certificado', 'priority' => 'urgent', 'assigned_to' => $ana->id,
            'due_at' => '2026-10-15 10:00', 'remind_at' => '2026-10-15 08:00']);
        $mine = $service->create($this->a['admin'], ['title' => 'Sin responsable', 'priority' => 'low', 'remind_at' => '2026-10-15 08:00']);
        $closed = $service->create($this->a['admin'], ['title' => 'Cerrada', 'priority' => 'low', 'assigned_to' => $ana->id, 'remind_at' => '2026-10-15 08:00']);
        $service->setStatus($this->a['admin'], $closed, 'done');
        $before = $this->notificationsOf($ana);
        auth()->logout();

        // Antes de la hora: nada.
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertSame($before, $this->notificationsOf($ana));

        // A la hora: uno a la responsable, uno al creador (sin responsable), nada por la cerrada. Dos corridas: no duplica.
        $this->travelTo(Carbon::parse('2026-10-15 08:05'));
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertSame($before + 1, $this->notificationsOf($ana));
        $this->assertSame(1, $this->notificationsOf($this->a['admin']));
        $this->assertNotNull($task->fresh()->reminded_at);
        $this->assertNull($closed->fresh()->reminded_at);

        // Nuevo recordatorio: vuelve a avisar.
        $this->actingAs($this->a['admin']);
        $service->update($this->a['admin'], $task->fresh(), ['remind_at' => '2026-10-15 09:00']);
        auth()->logout();
        $this->travelTo(Carbon::parse('2026-10-15 09:01'));
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertSame($before + 2, $this->notificationsOf($ana));

        // Vencida: en «Vencidas» y en el Centro de atención.
        $this->travelTo(Carbon::parse('2026-10-15 11:00'));
        $this->actingAs($this->a['admin']);
        $this->assertTrue($task->fresh()->isOverdue());
        $this->assertSame(1, Task::overdue()->count());
        $item = app(AttentionCenter::class)->basic($this->a['company'])->firstWhere('title', 'Tareas vencidas');
        $this->assertSame(1, $item['count']);
    }

    public function test_tasks_never_cross_companies(): void
    {
        $this->actingAs($this->a['admin']);
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'De A', 'priority' => 'low']);

        auth()->logout();
        $b = $this->makeTenant();
        $this->inPanel($b['admin']);
        $this->assertFalse(TaskResource::getEloquentQuery()->whereKey($task->id)->exists());
        $this->get(TaskResource::getUrl('edit', ['record' => $task]))->assertNotFound();
        $this->denied(fn () => app(TaskService::class)->update($b['admin'], $task, ['title' => 'Tomada']), 404);
        $this->denied(fn () => app(TaskService::class)->setStatus($b['admin'], $task, 'done'), 404);
        $this->assertSame('De A', $task->fresh()->title);
    }

    public function test_contact_history_belongs_to_the_client_and_persists(): void
    {
        $ana = $this->staff(['contacts' => 'manage', 'clients' => 'view'], 'Ana');
        $this->inPanel($ana);
        $quote = Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'client_id' => $this->client->id]);

        // Desde la ficha del cliente.
        Livewire::test(InteractionsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class])
            ->callTableAction('create', data: ['occurred_at' => '2026-10-14 08:30', 'type' => 'call', 'result' => 'follow_up', 'quote_id' => $quote->id, 'notes' => 'Pide volver a llamar el lunes.'])
            ->assertHasNoTableActionErrors();

        $contact = ClientInteraction::sole();
        $this->assertSame([$this->a['company']->id, $this->client->id, $ana->id, 'Ana', 'call', 'follow_up', $quote->id],
            [(int) $contact->company_id, (int) $contact->client_id, (int) $contact->user_id, $contact->user_name, $contact->type, $contact->result, (int) $contact->quote_id]);
        $this->assertSame('2026-10-14 08:30', $contact->occurred_at->format('Y-m-d H:i'));
        $this->assertSame('staff', ActivityLog::where('action', 'client_interaction.created')->sole()->actor_role);

        // Vínculos: el edificio tiene que ser de ese cliente; otra empresa, nada.
        $otherClientBuilding = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->invalid(fn () => app(ClientInteractionService::class)->record($ana, ['client_id' => $this->client->id, 'building_id' => $otherClientBuilding->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()]), 'building_id');
        $this->invalid(fn () => app(ClientInteractionService::class)->record($ana, ['client_id' => $this->client->id, 'type' => 'fax', 'result' => 'resolved', 'occurred_at' => now()]), 'type');

        // No se borra; el historial queda aunque se borre a quien lo registró.
        $this->assertFalse(ClientInteractionResource::canDelete($contact));
        auth()->logout();
        $ana->forceDelete();
        $contact->refresh();
        $this->assertNull($contact->user_id);
        $this->assertSame('Ana', $contact->user_name);

        // Otra empresa: ni lo ve ni lo registra con un cliente ajeno.
        $b = $this->makeTenant();
        $this->inPanel($b['admin']);
        $this->assertFalse(ClientInteractionResource::getEloquentQuery()->whereKey($contact->id)->exists());
        $this->invalid(fn () => app(ClientInteractionService::class)->record($b['admin'], ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()]), 'client_id');
        $this->denied(fn () => app(ClientInteractionService::class)->update($b['admin'], $contact, ['client_id' => $this->client->id, 'type' => 'note', 'result' => 'resolved', 'occurred_at' => now()]), 404);
    }

    public function test_contacts_and_tasks_follow_their_own_area_and_relations_inside_a_client_too(): void
    {
        // Solo «Clientes»: la ficha abre, pero ni contactos ni cobranzas dentro.
        $clientsOnly = $this->staff(['clients' => 'manage'], 'Solo clientes');
        $this->inPanel($clientsOnly);
        foreach ([InteractionsRelationManager::class, ServicesRelationManager::class, AccountStatementRelationManager::class] as $relation) {
            $this->assertFalse($relation::canViewForRecord($this->client, EditClient::class), $relation);
        }
        $this->assertFalse(ClientInteractionResource::canAccess());
        $this->assertFalse(TaskResource::canAccess());
        $this->denied(fn () => app(ClientInteractionService::class)->record($clientsOnly, ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()]));

        // Contactos con solo «ver»: los ve, no registra.
        $viewer = $this->staff(['contacts' => 'view', 'clients' => 'view'], 'Mira contactos');
        $this->inPanel($viewer);
        $this->assertTrue(InteractionsRelationManager::canViewForRecord($this->client, EditClient::class));
        $this->assertTrue(ClientInteractionResource::canAccess());
        $this->assertFalse(ClientInteractionResource::canCreate());
        $this->denied(fn () => app(ClientInteractionService::class)->record($viewer, ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()]));

        // El dueño, todo como siempre (también cobranzas dentro del cliente).
        $this->inPanel($this->a['admin']);
        foreach ([InteractionsRelationManager::class, ServicesRelationManager::class, AccountStatementRelationManager::class] as $relation) {
            $this->assertTrue($relation::canViewForRecord($this->client, EditClient::class), $relation);
        }
    }

    public function test_saving_permissions_keeps_the_areas_the_form_did_not_send(): void
    {
        $ana = $this->staff(['tasks' => 'manage', 'contacts' => 'view', 'clients' => 'view'], 'Ana');
        $this->actingAs($this->a['admin']);

        // Un formulario que solo manda «Clientes» (como uno anterior a las áreas nuevas): tareas y contactos quedan.
        app(StaffManager::class)->setPermissions($this->a['admin'], $ana, ['clients' => 'manage']);
        $this->assertEqualsCanonicalizing(['clients.manage', 'tasks.manage', 'contacts.view'], Access::permissionsOf($ana->fresh()));

        // Y al revés: cambiar tareas no toca clientes.
        app(StaffManager::class)->setPermissions($this->a['admin'], $ana->fresh(), ['tasks' => 'none']);
        $this->assertEqualsCanonicalizing(['clients.manage', 'contacts.view'], Access::permissionsOf($ana->fresh()));
    }
}
