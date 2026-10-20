<?php

namespace Tests\Feature\DeliveryNotes;

use App\Jobs\NotifyCompanyAdmins;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\PortalMembership;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkCompletedNotification;
use App\Services\Notifications\ClientShareNotifier;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug "aparece otro remito de la nada": los avisos de remitos tienen que
 * llevar al remito correcto (los remitos se abren por NÚMERO, que se repite
 * entre empresas y no coincide con el id), no crear remitos ni avisos de
 * más, y no avisarle a quien firmó su propio trabajo.
 */
class RemitoNotificationIntegrityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Los avisos "después de responder" corren en el momento (en las
        // pruebas, cada request vuelve a correr los de requests anteriores).
        app(Dispatcher::class)->withoutDispatchingAfterResponses();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->a['building']->update(['elevator_count' => 1]);

        // La empresa B ya tiene remitos: en A, los ids y los números no coinciden.
        foreach (range(1, 3) as $i) {
            DeliveryNote::factory()->create(['company_id' => $this->b['company']->id, 'building_id' => $this->b['building']->id, 'user_id' => $this->b['technician']->id]);
        }
    }

    private function sign(User $user, array $fields): TestResponse
    {
        return $this->actingAs($user)->post("/{$this->a['company']->slug}/delivery-notes/store", $fields + [
            'building_id' => $this->a['building']->id,
            'description' => 'Trabajo realizado',
            'month' => now()->month,
            'year' => now()->year,
            'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0,
            'performed' => '1',
            'signature_name' => 'Firma',
            'signature' => $this->validSignature(),
        ]);
    }

    private function workOrderFor(User $user): WorkOrder
    {
        $order = WorkOrder::factory()->inProgress()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id]);
        $order->users()->attach($user->id);

        return $order;
    }

    private function completedFor(User $user)
    {
        return $user->notifications()->where('type', WorkCompletedNotification::class)->get();
    }

    public function test_the_admin_notification_opens_the_signed_remito_and_not_the_one_whose_number_equals_its_id(): void
    {
        // Dos remitos en A: el segundo tiene id 5 y número 00000002; el remito
        // número 00000001 tiene id 4. Con el id en la URL, se abría otro remito (o 404).
        $this->sign($this->a['technician'], ['assignment_type' => 'maintenance', 'submission_token' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $order = $this->workOrderFor($this->a['technician']);
        $this->sign($this->a['technician'], ['work_order_id' => $order->id, 'assignment_type' => 'work_order', 'description' => 'Cambio de contactor'])->assertSessionHasNoErrors();

        $note = DeliveryNote::withoutGlobalScopes()->where('work_order_id', $order->id)->sole();
        $this->assertNotSame((int) $note->number, $note->id);

        $row = $this->completedFor($this->a['admin'])->sortByDesc('created_at')->firstWhere('data.delivery_note_id', $note->id);
        $this->assertNotNull($row);
        $this->assertStringEndsWith('/admin/delivery-notes/'.$note->number, $row->data['actions'][0]['url']);

        $this->actingAs($this->a['admin']);
        $destination = $this->get(route('notifications.open', $row->id))->assertRedirect()->headers->get('Location');
        $this->assertStringEndsWith('/admin/delivery-notes/'.$note->number, $destination);
        $this->get($destination)->assertOk()->assertSee('Cambio de contactor');
    }

    public function test_notifications_saved_before_the_fix_still_open_the_right_remito(): void
    {
        $order = $this->workOrderFor($this->a['technician']);
        $this->sign($this->a['technician'], ['work_order_id' => $order->id, 'assignment_type' => 'work_order', 'description' => 'Trabajo de la orden'])->assertSessionHasNoErrors();
        $note = DeliveryNote::withoutGlobalScopes()->where('work_order_id', $order->id)->sole();

        // Aviso viejo: botón con el id y sin delivery_note_id.
        $row = $this->completedFor($this->a['admin'])->sole();
        $data = $row->data;
        unset($data['delivery_note_id']);
        $data['actions'][0]['url'] = url('/admin/delivery-notes/'.$note->id);
        $row->forceFill(['data' => $data])->save();

        $this->actingAs($this->a['admin'])->get(route('notifications.open', $row->id))
            ->assertRedirect('/admin/delivery-notes/'.$note->number);

        // Un id de otra empresa en un aviso viejo no lleva a ese remito.
        $foreign = DeliveryNote::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->first();
        $data['actions'][0]['url'] = url('/admin/delivery-notes/'.$foreign->id);
        $row->forceFill(['data' => $data])->save();
        $this->get(route('notifications.open', $row->id))->assertRedirect('/admin/delivery-notes/'.$foreign->id);
        $this->get('/admin/delivery-notes/'.$foreign->id)->assertNotFound();
    }

    public function test_each_job_creates_one_remito_and_one_notification_per_admin_even_if_the_form_is_sent_twice(): void
    {
        $token = (string) Str::uuid();
        $this->sign($this->a['technician'], ['assignment_type' => 'maintenance', 'submission_token' => $token])->assertSessionHasNoErrors();
        $this->sign($this->a['technician'], ['assignment_type' => 'maintenance', 'submission_token' => $token])->assertSessionHasNoErrors();

        $order = $this->workOrderFor($this->a['technician']);
        $this->sign($this->a['technician'], ['work_order_id' => $order->id, 'assignment_type' => 'work_order'])->assertSessionHasNoErrors();
        $this->sign($this->a['technician'], ['work_order_id' => $order->id, 'assignment_type' => 'work_order'])->assertStatus(409);

        $notes = DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->get();
        $this->assertCount(2, $notes);
        $this->assertSame(['00000001', '00000002'], $notes->pluck('number')->sort()->values()->all());
        $this->assertSame(1, $notes->where('work_order_id', $order->id)->count());
        $this->assertSame(1, BuildingVisit::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->where('visit_type', 'fixed')->count());

        // Un aviso por remito, cada uno con SU remito; nada para la otra empresa.
        $rows = $this->completedFor($this->a['admin']);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing($notes->pluck('id')->all(), $rows->pluck('data.delivery_note_id')->all());
        $this->assertCount(0, $this->completedFor($this->b['admin']));
    }

    public function test_whoever_signs_the_remito_is_not_notified_about_their_own_work(): void
    {
        $owner = $this->a['admin'];
        $partner = User::factory()->admin()->create(['company_id' => $this->a['company']->id]);

        $order = $this->workOrderFor($owner);
        $this->sign($owner, ['work_order_id' => $order->id, 'assignment_type' => 'work_order'])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->completedFor($owner));
        $this->assertCount(1, $this->completedFor($partner));

        // El trabajo de un técnico le llega a los dos.
        $this->sign($this->a['technician'], ['assignment_type' => 'maintenance', 'submission_token' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->completedFor($owner));
        $this->assertCount(2, $this->completedFor($partner));
    }

    public function test_the_job_never_notifies_twice_nor_for_a_missing_remito(): void
    {
        $note = DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        NotifyCompanyAdmins::dispatchSync(NotifyCompanyAdmins::WORK_COMPLETED, $note->id);
        NotifyCompanyAdmins::dispatchSync(NotifyCompanyAdmins::WORK_COMPLETED, 999999);

        $this->assertCount(1, $this->completedFor($this->a['admin']));
        $this->assertSame($note->id, $this->completedFor($this->a['admin'])->sole()->data['delivery_note_id']);
    }

    public function test_a_client_with_two_companies_opens_the_remito_of_the_company_that_shared_it(): void
    {
        $clientA = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $clientB = Client::factory()->create(['company_id' => $this->b['company']->id]);
        $buildingA = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $clientA->id]);
        $buildingB = Building::factory()->create(['company_id' => $this->b['company']->id, 'client_id' => $clientB->id]);

        $laura = User::factory()->create(['email' => 'laura@consorcio.test']);
        $laura->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $clientA->company_id, 'client_id' => $clientA->id])->save();
        foreach ([$clientA, $clientB] as $client) {
            PortalMembership::firstOrNew(['user_id' => $laura->id, 'client_id' => $client->id])->forceFill(['company_id' => $client->company_id])->save();
        }
        DB::table('client_portal_buildings')->insert([
            ['user_id' => $laura->id, 'building_id' => $buildingA->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $laura->id, 'building_id' => $buildingB->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // El mismo número en las dos empresas, los dos compartidos.
        $noteA = DeliveryNote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $buildingA->id, 'description' => 'Remito de la empresa A']);
        $noteB = DeliveryNote::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->where('number', $noteA->number)->sole();
        $noteB->forceFill(['building_id' => $buildingB->id, 'description' => 'Remito de la empresa B'])->saveQuietly();
        $this->assertSame($noteA->number, $noteB->number);
        $noteA->shareWithClient(true);
        $noteB->shareWithClient(true);
        app(ClientShareNotifier::class)->shared([$noteB]);

        $row = DatabaseNotification::where('notifiable_id', $laura->id)->sole();
        $this->assertSame($this->b['company']->id, (int) $row->company_id);

        // La empresa activa del portal es A; el aviso es de B.
        $this->actingAs($laura);
        $this->get(route('portal.home'))->assertOk();
        $destination = $this->get(route('portal.notifications.open', $row->id))->assertRedirect()->headers->get('Location');
        $this->get($destination)->assertOk()->assertSee('Remito de la empresa B')->assertDontSee('Remito de la empresa A');
    }
}
