<?php

namespace Tests\Feature\Notifications;

use App\Filament\Pages\CompanyExports;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Elevators\Pages\EditElevator;
use App\Filament\Resources\Elevators\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\CompanyExport;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\AssignmentChangedNotification;
use App\Notifications\App\ExportFinishedNotification;
use App\Notifications\App\SharedWithClientNotification;
use App\Notifications\App\VisitsReminderNotification;
use App\Notifications\MailOnlyNotification;
use App\Notifications\WorkOrderAssignedNotification;
use App\Services\Elevators\ElevatorDocumentService;
use App\Services\Notifications\Notifier;
use App\Services\Portal\PortalInvitations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Avisos de los tres perfiles: a quién llegan (y a quién nunca), por qué
 * canal, sin duplicados, sin cuentas desactivadas, y bandejas aisladas.
 */
class NotificationSystemTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    private Building $authorized;

    private Building $private;

    private User $portal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        $company = $this->a['company']->id;
        $this->client = Client::factory()->create(['company_id' => $company]);
        $this->authorized = Building::factory()->create(['company_id' => $company, 'client_id' => $this->client->id, 'name' => 'Edificio Visible']);
        $this->private = Building::factory()->create(['company_id' => $company, 'client_id' => $this->client->id, 'name' => 'Edificio Reservado']);
        $this->portal = $this->portalUser($this->client, [$this->authorized->id], 'cliente@consorcio.test');
    }

    private function portalUser(Client $client, array $buildingIds, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync($buildingIds);

        return $user;
    }

    private function note(Building $building): DeliveryNote
    {
        return DeliveryNote::factory()->create(['building_id' => $building->id, 'user_id' => $this->a['technician']->id]);
    }

    private function shareFromPanel(array $records): void
    {
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListDeliveryNotes::class)->callTableBulkAction('shareWithClient', $records);
    }

    // --- Técnicos -------------------------------------------------------

    public function test_a_new_assignment_notifies_only_that_technician_and_a_removal_stops_the_reminders(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->detach(); // el técnico solo tiene lo que se asigna acá
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListBuildings::class)
            ->callTableAction('assignTechnician', $this->authorized, data: ['user_ids' => [$this->a['technician']->id], 'type' => 'maintenance'])
            ->assertHasNoTableActionErrors();

        $notice = $this->a['technician']->notifications()->sole();
        $this->assertSame('Nuevo edificio asignado', $notice->data['title']);
        $this->assertStringContainsString('Edificio Visible', $notice->data['body']);
        $this->assertSame("/{$this->a['company']->slug}/buildings", $notice->data['path']);
        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame(0, $this->b['technician']->notifications()->count());

        // Reasignación: el anterior recibe "ya no lo tenés", sin enlace, y deja de recibir recordatorios.
        Livewire::test(ListBuildings::class)->callTableAction('removeTechnician', $this->authorized, data: ['assignment' => $this->a['technician']->id.'-maintenance']);
        Livewire::test(ListBuildings::class)->callTableAction('assignTechnician', $this->authorized, data: ['user_ids' => [$other->id], 'type' => 'maintenance']);
        $removed = $this->a['technician']->notifications()->get()->firstWhere('data.title', 'Te quitaron una asignación');
        $this->assertSame('Te quitaron una asignación', $removed->data['title']);
        $this->assertNull($removed->data['path']);

        auth()->logout();
        $this->travelTo(Carbon::parse('2026-10-21 08:00'));
        $this->artisan('notifications:visits')->assertSuccessful();
        $this->assertSame(1, $other->notifications()->where('type', VisitsReminderNotification::class)->count());
        $this->assertSame(0, $this->a['technician']->notifications()->where('type', VisitsReminderNotification::class)->count());
    }

    public function test_work_order_assignment_lands_in_the_technician_inbox(): void
    {
        $order = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'status' => 'pending']);
        $order->users()->attach($this->a['technician']->id);
        SendWorkOrderAssignedNotification::dispatchSync($order->id, $this->a['technician']->id);

        $notice = $this->a['technician']->notifications()->sole();
        $this->assertSame(WorkOrderAssignedNotification::class, $notice->type);
        $this->actingAs($this->a['technician'])->get(route('notifications.index'))->assertOk()->assertSee('Nueva orden de trabajo');
        $this->get(route('notifications.open', $notice->id))->assertRedirect("/{$this->a['company']->slug}/work-orders/{$order->id}");
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_visit_reminders_are_sent_once_and_respect_the_flexible_month(): void
    {
        $this->a['building']->users()->updateExistingPivot($this->a['technician']->id, ['type' => 'maintenance']);
        $this->a['company']->forceFill(['trial_ends_at' => Carbon::parse('2027-06-01')])->save(); // acceso vigente todo el período

        // A mitad de mes no se molesta a nadie.
        $this->travelTo(Carbon::parse('2026-10-14 08:00'));
        $this->artisan('notifications:visits');
        $this->assertSame(0, DatabaseNotification::count());

        // Desde el 20: pendiente (una sola vez aunque corra todos los días).
        $this->travelTo(Carbon::parse('2026-10-21 08:00'));
        $this->artisan('notifications:visits');
        $this->artisan('notifications:visits');
        $this->assertSame(['Visitas pendientes este mes'], $this->a['technician']->notifications()->pluck('data')->pluck('title')->all());

        // Hecho el remito: el mes siguiente no aparece como vencido.
        BuildingVisit::create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id,
            'visit_type' => 'fixed', 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026, 'status' => 'done', 'visited_at' => now(), 'source' => 'building']);
        $this->travelTo(Carbon::parse('2026-11-02 08:00'));
        $this->artisan('notifications:visits');
        $this->assertSame(0, $this->a['technician']->notifications()->where('data->title', 'Visitas que quedaron sin hacer')->count());

        // Sin remito en noviembre: el 1/12 vencido al técnico y resumen (con correo) al admin.
        $this->travelTo(Carbon::parse('2026-12-01 08:00'));
        $this->artisan('notifications:visits');
        $this->artisan('notifications:visits');
        $this->assertSame(1, $this->a['technician']->notifications()->where('dedupe_key', 'visits-overdue:2026-11')->count());
        $summary = $this->a['admin']->notifications()->where('dedupe_key', 'visits-overdue_summary:2026-11')->sole();
        $this->assertNotNull($summary->mailed_at);
        $this->assertSame(1, $this->a['admin']->notifications()->where('dedupe_key', 'like', 'visits-overdue_summary:%')->count());
    }

    // --- Clientes -------------------------------------------------------

    public function test_sharing_notifies_only_authorized_clients_with_one_mail_per_action(): void
    {
        Notification::fake();
        $second = $this->portalUser($this->client, [$this->authorized->id, $this->private->id], 'consejo@consorcio.test');
        $otherClient = $this->portalUser(Client::factory()->create(['company_id' => $this->a['company']->id]), [], 'otro@cliente.test');
        $visible = [$this->note($this->authorized), $this->note($this->authorized)];
        $reserved = $this->note($this->private);

        $this->shareFromPanel([...$visible, $reserved]);

        $this->assertSame(2, $this->portal->notifications()->count());           // solo los de su edificio
        $this->assertSame(3, $second->notifications()->count());                 // tiene los dos edificios
        $this->assertSame(0, $otherClient->notifications()->count());
        $this->assertSame(0, $this->a['admin']->notifications()->where('type', SharedWithClientNotification::class)->count());
        Notification::assertSentToTimes($this->portal, MailOnlyNotification::class, 1); // un correo por acción, no por documento
        Notification::assertSentToTimes($second, MailOnlyNotification::class, 1);
        $this->assertStringNotContainsString('Edificio Reservado', json_encode($this->portal->notifications()->pluck('data')));

        // Volver a compartir lo mismo no duplica.
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListDeliveryNotes::class)->callTableBulkAction('unshareWithClient', $visible);
        $this->shareFromPanel($visible);
        $this->assertSame(2, $this->portal->notifications()->count());
        Notification::assertSentToTimes($this->portal, MailOnlyNotification::class, 1);
    }

    public function test_private_documents_and_finished_work_never_notify_the_client(): void
    {
        $elevator = Elevator::where('building_id', $this->authorized->id)->first();
        $this->actingInPanel($this->a['admin']);
        $doc = app(ElevatorDocumentService::class)->store($elevator, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"), 'certificate', 'Habilitación', null, $this->a['admin']);

        // Cargar un documento o firmar un remito NO lo comparte ni avisa.
        $this->note($this->authorized);
        $this->assertSame(0, $this->portal->notifications()->count());
        $this->assertFalse($doc->fresh()->shared_with_client);

        // Compartirlo explícitamente sí.
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $elevator, 'pageClass' => EditElevator::class])
            ->call('updateTableColumnState', 'shared_with_client', (string) $doc->getKey(), true);
        $notice = $this->portal->notifications()->sole();
        $this->assertStringContainsString('Habilitación', $notice->data['body']);
        $this->assertSame("/portal/edificios/{$this->authorized->id}", $notice->data['path']);
    }

    public function test_clients_lose_notifications_and_links_when_access_is_revoked(): void
    {
        $note = $this->note($this->authorized);
        $this->shareFromPanel([$note]);
        $notice = $this->portal->notifications()->sole();

        $this->actingAs($this->portal)->get(route('portal.notifications.open', $notice->id))->assertRedirect("/portal/remitos/{$note->number}");
        $this->get("/portal/remitos/{$note->number}")->assertOk();

        // Se le quita el edificio: el enlace del aviso ya no abre nada y no recibe nuevos.
        $this->portal->portalBuildings()->sync([]);
        $this->get(route('portal.notifications.open', $notice->id))->assertRedirect("/portal/remitos/{$note->number}");
        $this->get("/portal/remitos/{$note->number}")->assertNotFound();
        $this->shareFromPanel([$this->note($this->authorized)]);
        $this->assertSame(1, $this->portal->notifications()->count());
    }

    // --- Admins ---------------------------------------------------------

    public function test_admins_get_the_export_result_in_app_and_by_mail(): void
    {
        Notification::fake();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(CompanyExports::class)->call('requestExport');
        auth()->logout();
        $this->artisan('exports:process');
        $this->artisan('exports:process');

        $notice = $this->a['admin']->notifications()->where('type', ExportFinishedNotification::class)->sole();
        $this->assertSame('Tu exportación está lista', $notice->data['title']);
        $this->assertStringNotContainsString('/files/exports/', json_encode($notice->data)); // sin enlace directo de descarga
        $this->assertSame(0, $this->a['technician']->notifications()->count());
        $this->assertSame(0, $this->b['admin']->notifications()->count());
        Notification::assertSentToTimes($this->a['admin'], MailOnlyNotification::class, 1);
    }

    // --- Garantías generales ---------------------------------------------

    public function test_inboxes_are_private_per_user_and_company(): void
    {
        $this->shareFromPanel([$this->note($this->authorized)]);
        $clientNotice = $this->portal->notifications()->sole();
        $techNotice = app(Notifier::class)->sendTo($this->a['technician'], new AssignmentChangedNotification('assigned', 'el mantenimiento de X', '/'), $this->a['company']->id);
        $otherTech = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        // Otro técnico de la misma empresa y uno de otra empresa: no la ven, no la abren, no la marcan.
        foreach ([$otherTech, $this->b['technician']] as $intruder) {
            $this->actingAs($intruder)->get(route('notifications.open', $techNotice->id))->assertNotFound();
            $this->get(route('notifications.index'))->assertOk()->assertDontSee('el mantenimiento de X');
            $this->post(route('notifications.read-all'));
            $this->get(route('notifications.count'))->assertJson(['unread' => 0]);
        }
        $this->assertNull($techNotice->fresh()->read_at);

        // Un cliente no abre avisos de técnicos ni de otros clientes.
        auth()->logout(); // (crear datos sin sesión: BelongsToCompany fuerza la empresa del actor)
        $otherClient = $this->portalUser($this->client, [$this->authorized->id], 'otro@consorcio.test');
        $this->actingAs($otherClient)->get(route('portal.notifications.open', $clientNotice->id))->assertNotFound();
        $this->get(route('portal.notifications.open', $techNotice->id))->assertNotFound();
        $this->assertNull($clientNotice->fresh()->read_at);

        // El dueño sí: contador, marcar todas.
        $this->actingAs($this->portal)->get(route('portal.notifications.count'))->assertJson(['unread' => 1]);
        $this->get(route('portal.notifications'))->assertOk()->assertSee('Nueva información de Edificio Visible');
        $this->post(route('portal.notifications.read-all'));
        $this->assertNotNull($clientNotice->fresh()->read_at);
    }

    public function test_repeated_events_do_not_duplicate_and_disabled_accounts_get_nothing(): void
    {
        $notification = new AssignmentChangedNotification('wo_cancelled', 'la orden #1', key: 'wo-cancelled:1');
        $notifier = app(Notifier::class);

        $this->assertNotNull($notifier->sendTo($this->a['technician'], $notification, $this->a['company']->id));
        $this->assertNull($notifier->sendTo($this->a['technician'], $notification, $this->a['company']->id));
        $this->assertSame(1, $this->a['technician']->notifications()->count());

        // Cuenta desactivada, otra empresa, SuperAdmin, empresa vencida: nada.
        $disabled = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $disabled->delete();
        $this->assertNull($notifier->sendTo($disabled, $notification, $this->a['company']->id));
        $this->assertNull($notifier->sendTo($this->b['technician'], $notification, $this->a['company']->id));
        $super = User::factory()->create(['is_super_admin' => true]);
        $this->assertNull($notifier->sendTo($super, $notification, $this->a['company']->id));
        $this->a['company']->forceFill(['is_active' => false])->save();
        $this->assertNull($notifier->sendTo($this->a['admin'], new AssignmentChangedNotification('wo_cancelled', 'x', key: 'k2'), $this->a['company']->id));
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $disabled->id)->count());
    }

    public function test_a_mail_failure_keeps_the_in_app_notification_and_is_not_marked_sent(): void
    {
        Log::spy();
        Notification::swap(new class extends NotificationFake
        {
            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('SMTP 535 user=secreto@proveedor.test');
            }
        });
        $export = new CompanyExport;
        $export->forceFill(['id' => 99, 'company_id' => $this->a['company']->id, 'status' => CompanyExport::FAILED]);

        $row = app(Notifier::class)->sendTo($this->a['admin'], new ExportFinishedNotification($export), $this->a['company']->id);

        $this->assertNotNull($row);
        $row = $row->fresh();
        $this->assertNull($row->mailed_at);
        $this->assertNotNull($row->mail_failed_at);
        $this->assertSame(1, $this->a['admin']->notifications()->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => ! str_contains(json_encode($c), 'secreto'));
    }

    public function test_notifications_never_carry_secrets_or_tokens(): void
    {
        $this->shareFromPanel([$this->note($this->authorized)]);
        PortalInvitations::markActivated($this->portal);

        $all = json_encode(DatabaseNotification::all()->pluck('data'));
        $this->assertStringNotContainsString('$2y$', $all);
        $this->assertStringNotContainsString('token', strtolower($all));
        $this->assertStringNotContainsString('data:image', $all);
    }
}
