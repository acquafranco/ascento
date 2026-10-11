<?php

namespace Tests\Feature\Notifications;

use App\Events\UserNotificationsChanged;
use App\Filament\Pages\Dashboard;
use App\Livewire\DatabaseNotifications;
use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Estado leído / no leído, igual para los cuatro perfiles:
 * - abrir un aviso lo marca leído en el servidor (también desde la campanita);
 * - "Marcar como leída" de a uno, también sin destino;
 * - al cerrar la campanita o salir de la bandeja, solo se marcan los vistos;
 * - cada usuario toca solo sus avisos; los cambios avisan a sus otras pestañas.
 */
class NotificationReadStateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        // Tiempo real "configurado" para ver los avisos a otras pestañas (el broadcast real no sale: Event::fake).
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'clave-test']);
        Event::fake([UserNotificationsChanged::class]);
    }

    private function notice(User $user, array $data = []): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'tests.notice',
            'data' => ['format' => 'filament', 'title' => 'Aviso de prueba', 'body' => 'Texto'] + $data,
        ]);
    }

    private function portalUser(): User
    {
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $user = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $user->portalBuildings()->sync([$building->id]);

        return $user;
    }

    // --- Abrir --------------------------------------------------------------

    public function test_admins_and_super_admins_open_bell_notices_through_the_server_and_they_get_read(): void
    {
        $super = User::factory()->superAdmin()->create();

        foreach ([$this->a['admin'], $super] as $user) {
            $notice = $this->notice($user, ['path' => '/admin/buildings']);

            $this->actingAs($user)->get(route('notifications.open', $notice->id))->assertRedirect('/admin/buildings');
            $this->assertNotNull($notice->fresh()->read_at);
        }

        Event::assertDispatchedTimes(UserNotificationsChanged::class, 2);
        // La bandeja de técnicos sigue siendo solo de técnicos: el admin va a su campanita.
        $this->actingAs($this->a['admin'])->get(route('notifications.index'))->assertRedirect('/admin');
    }

    public function test_the_destination_is_the_saved_path_the_button_or_the_report_and_never_another_site(): void
    {
        $admin = $this->a['admin'];
        $this->actingAs($admin);

        $button = $this->notice($admin, ['actions' => [['name' => 'view', 'url' => url('/admin/clients?tab=1')]]]);
        $this->get(route('notifications.open', $button->id))->assertRedirect('/admin/clients?tab=1');

        $report = $this->notice($admin, ['report_id' => 15]);
        $this->get(route('notifications.open', $report->id))->assertRedirect('/admin/reports/15');

        foreach (['https://otro-sitio.test/robar', '//otro-sitio.test', '/\\otro-sitio.test'] as $evil) {
            $notice = $this->notice($admin, ['path' => $evil, 'actions' => [['url' => $evil]]]);
            $this->get(route('notifications.open', $notice->id))->assertRedirect('/admin');
            $this->assertNotNull($notice->fresh()->read_at, 'igual queda leído');
        }
    }

    public function test_opening_twice_is_harmless_and_does_not_announce_again(): void
    {
        $notice = $this->notice($this->a['technician'], ['path' => '/']);
        $this->actingAs($this->a['technician']);

        $this->get(route('notifications.open', $notice->id))->assertRedirect('/');
        $readAt = $notice->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->get(route('notifications.open', $notice->id))->assertRedirect('/');

        $this->assertEquals($readAt, $notice->fresh()->read_at);
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
    }

    public function test_nobody_opens_or_marks_someone_elses_notice(): void
    {
        $adminNotice = $this->notice($this->a['admin'], ['path' => '/admin']);
        $techNotice = $this->notice($this->a['technician'], ['path' => '/']);
        $portal = $this->portalUser();

        $attempts = [
            [$this->b['admin'], route('notifications.open', $adminNotice->id)],          // admin de otra empresa
            [User::factory()->admin()->create(['company_id' => $this->a['company']->id]), route('notifications.open', $adminNotice->id)], // otro admin de la misma
            [$this->b['technician'], route('notifications.open', $techNotice->id)],
            [User::factory()->technician()->create(['company_id' => $this->a['company']->id]), route('notifications.open', $techNotice->id)],
            [$portal, route('portal.notifications.open', $techNotice->id)],              // el portal no ve avisos internos
            [$portal, route('portal.notifications.open', $adminNotice->id)],
        ];

        foreach ($attempts as [$intruder, $url]) {
            $this->actingAs($intruder)->get($url)->assertNotFound();
        }

        $this->assertNull($adminNotice->fresh()->read_at);
        $this->assertNull($techNotice->fresh()->read_at);
    }

    // --- Campanita del panel ------------------------------------------------

    public function test_the_bell_shows_open_and_mark_as_read_for_each_unread_notice(): void
    {
        $admin = $this->a['admin'];
        $withPath = $this->notice($admin, ['title' => 'Con destino', 'path' => '/admin/buildings', 'actions' => [['name' => 'view', 'label' => 'Ver orden', 'url' => url('/admin/buildings')]]]);
        $withoutPath = $this->notice($admin, ['title' => 'Falló un backup de Ascento']);
        $read = $this->notice($admin, ['title' => 'Ya leído']);
        $read->markAsRead();

        $this->actingInPanel($admin);

        Livewire::test(DatabaseNotifications::class)
            ->assertSeeHtml('href="'.route('notifications.open', $withPath->id).'"')
            ->assertSee('Ver orden')
            ->assertSeeHtml('wire:click="markNotificationAsRead(\''.$withoutPath->id.'\')"')
            ->assertSeeHtml('data-notification-id="'.$withoutPath->id.'"')
            ->assertDontSeeHtml('href="'.route('notifications.open', $withoutPath->id).'"') // sin destino: sin botón "Abrir"
            ->assertDontSeeHtml('markNotificationAsRead(\''.$read->id.'\')');
    }

    public function test_mark_as_read_one_by_one_only_touches_own_unread_notices(): void
    {
        $mine = $this->notice($this->a['admin']);
        $foreign = $this->notice($this->b['admin']);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(DatabaseNotifications::class)
            ->call('markNotificationAsRead', $foreign->id)
            ->call('markNotificationAsRead', 'no-es-un-id')
            ->call('markNotificationAsRead', $mine->id)
            ->call('markNotificationAsRead', $mine->id); // repetido

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
    }

    public function test_closing_a_notice_marks_it_read_and_never_deletes_it(): void
    {
        $notice = $this->notice($this->a['admin']);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(DatabaseNotifications::class)->dispatch('notificationClosed', id: $notice->id);

        $this->assertNotNull($notice->fresh()?->read_at);
    }

    public function test_closing_the_bell_marks_only_the_notices_that_were_seen(): void
    {
        $admin = $this->a['admin'];
        $seen = [$this->notice($admin), $this->notice($admin)];
        $notSeen = $this->notice($admin);
        $foreign = $this->notice($this->b['admin']);
        $this->actingInPanel($admin);

        Livewire::test(DatabaseNotifications::class)
            ->dispatch(DatabaseNotifications::SEEN_EVENT, ids: [$seen[0]->id, $seen[1]->id, $foreign->id, 'basura', 123, null]);

        $this->assertNotNull($seen[0]->fresh()->read_at);
        $this->assertNotNull($seen[1]->fresh()->read_at);
        $this->assertNull($notSeen->fresh()->read_at, 'lo que no se vio queda sin leer');
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame(1, $admin->unreadNotifications()->count());
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
    }

    public function test_opening_the_bell_alone_marks_nothing(): void
    {
        $notice = $this->notice($this->a['admin']);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(DatabaseNotifications::class)->dispatch(DatabaseNotifications::SEEN_EVENT, ids: []);

        $this->assertNull($notice->fresh()->read_at);
        Event::assertNotDispatched(UserNotificationsChanged::class);
    }

    public function test_seen_requests_are_capped(): void
    {
        $admin = $this->a['admin'];
        $ids = collect(range(1, 105))->map(fn () => $this->notice($admin)->id)->all();
        $this->actingInPanel($admin);

        Livewire::test(DatabaseNotifications::class)->dispatch(DatabaseNotifications::SEEN_EVENT, ids: $ids);

        $this->assertSame(5, $admin->unreadNotifications()->count());
    }

    public function test_mark_all_announces_to_other_tabs(): void
    {
        $this->notice($this->a['admin']);
        $this->notice($this->a['admin']);
        $this->actingInPanel($this->a['admin']);

        Livewire::test(DatabaseNotifications::class)->call('markAllNotificationsAsRead');

        $this->assertSame(0, $this->a['admin']->unreadNotifications()->count());
        Event::assertDispatched(UserNotificationsChanged::class, fn ($e) => $e->user->is($this->a['admin']));
    }

    public function test_the_panel_loads_the_seen_script_and_the_custom_bell(): void
    {
        $this->actingAs($this->a['admin'])->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('ascento-notifications-seen', false)
            ->assertSee('fi-topbar-database-notifications-btn', false);
    }

    // --- Bandeja de técnicos y portal ---------------------------------------

    public function test_leaving_the_inbox_marks_only_the_notices_that_were_seen(): void
    {
        $tech = $this->a['technician'];
        $seen = $this->notice($tech);
        $notSeen = $this->notice($tech);
        $foreign = $this->notice($this->b['technician']);

        $this->actingAs($tech)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-unread', false)
            ->assertSee(json_encode(route('notifications.seen')), false);

        $this->postJson(route('notifications.seen'), ['ids' => [$seen->id, $foreign->id, 'basura']])->assertNoContent();

        $this->assertNotNull($seen->fresh()->read_at);
        $this->assertNull($notSeen->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->getJson(route('notifications.count'))->assertJson(['unread' => 1]);
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);

        // Repetido: no cambia nada ni vuelve a avisar.
        $this->postJson(route('notifications.seen'), ['ids' => [$seen->id]])->assertNoContent();
        Event::assertDispatchedTimes(UserNotificationsChanged::class, 1);
    }

    public function test_portal_clients_use_their_own_inbox_with_the_same_rules(): void
    {
        $portal = $this->portalUser();
        $mine = $this->notice($portal);
        $internal = $this->notice($this->a['technician']);

        $this->actingAs($portal)->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('data-notification-id="'.$mine->id.'"', false)
            ->assertSee(json_encode(route('portal.notifications.seen')), false);

        $this->postJson(route('portal.notifications.seen'), ['ids' => [$mine->id, $internal->id]])->assertNoContent();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($internal->fresh()->read_at);

        // Cada perfil por su ruta: el cliente no usa la de técnicos y al revés
        // (la sección lo rechaza o lo redirige antes de tocar nada).
        $mine->forceFill(['read_at' => null])->save();
        $this->assertNotSame(204, $this->postJson(route('notifications.seen'), ['ids' => [$mine->id]])->status());
        $this->assertNotSame(204, $this->actingAs($this->a['technician'])->postJson(route('portal.notifications.seen'), ['ids' => [$internal->id]])->status());
        $this->assertNull($mine->fresh()->read_at);
        $this->assertNull($internal->fresh()->read_at);
    }

    public function test_panel_users_cannot_use_the_inbox_seen_endpoint(): void
    {
        $notice = $this->notice($this->a['admin']);

        $this->actingAs($this->a['admin'])->postJson(route('notifications.seen'), ['ids' => [$notice->id]])->assertForbidden();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_guests_cannot_mark_anything(): void
    {
        $notice = $this->notice($this->a['technician']);

        $this->postJson(route('notifications.seen'), ['ids' => [$notice->id]])->assertUnauthorized();
        $this->get(route('notifications.open', $notice->id))->assertRedirect(route('login'));
        $this->assertNull($notice->fresh()->read_at);
    }
}
