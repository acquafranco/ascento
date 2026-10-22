<?php

namespace Tests\Feature\Marketplace;

use App\Events\UserNotificationsChanged;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Notifications\Marketplace\MarketplaceNotice;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Avisos del marketplace con la infraestructura de Ascento (Notifier):
 * destinatarios autorizados, una sola vez por evento, tiempo real, push,
 * correo con constancia, bandeja del proveedor y la tarea programada.
 */
class MarketplaceNotificationsTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private function quotedRequest(int $validDays = 2): array
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();
        $service = app(MarketplaceRequests::class);
        $request = $service->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');
        $service->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 10], $validDays, null);

        return [$supplier, $supplierUser, $a, $request->fresh()];
    }

    public function test_quote_expiry_is_announced_once_and_an_expired_quote_can_not_be_accepted(): void
    {
        [, $supplierUser, $a, $request] = $this->quotedRequest(1);
        $admin = $a['admin'];

        // Vence a fin del día siguiente (hora de Buenos Aires): aviso previo una sola vez, aunque la tarea corra dos veces.
        $this->assertSame('America/Argentina/Buenos_Aires', $request->quoteExpiresAt()->timezoneName);
        $this->travelTo($request->quoteExpiresAt()->copy()->subHours(5));
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertSame(1, $admin->notifications()->where('data->title', 'Una cotización vence pronto')->count());

        $this->travelTo($request->quoteExpiresAt()->copy()->addMinute());
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertSame(1, $admin->notifications()->where('data->title', 'Venció una cotización')->count());
        $this->assertSame(1, $supplierUser->notifications()->where('data->title', 'Venció tu cotización')->count());
        $this->assertNotNull($request->fresh()->expired_notified_at);

        try {
            app(MarketplaceRequests::class)->buyerMove($admin, $request->fresh(), MarketplaceRequest::ACCEPTED, null, $request->quoteVersion());
            $this->fail('Aceptó una cotización vencida');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('venció', $e->getMessage());
        }

        // Una cotización nueva tiene sus propios avisos.
        app(MarketplaceRequests::class)->quote($supplierUser, $request->fresh(), [$request->items()->value('id') => 12], 3, null);
        $this->assertNull($request->fresh()->expired_notified_at);
    }

    public function test_commercial_state_changes_notify_the_supplier_and_buyers_with_open_requests_once(): void
    {
        [$supplier, $supplierUser, $a] = $this->quotedRequest(10);
        $supplier->forceFill(['trial_started_at' => now(), 'trial_ends_at' => now()->addDays(2)])->save();
        config(['services.mercadopago.access_token' => 'APP_USR-TEST']);
        MarketplaceSettings::create(['trial_days' => 30, 'billing_enabled' => true, 'plan_price' => 25000, 'plan_currency' => 'ARS', 'international_free' => true]);

        Artisan::call('marketplace:tick'); // registra el estado inicial y avisa que la prueba termina pronto
        Artisan::call('marketplace:tick');
        $this->assertSame(1, $supplierUser->notifications()->where('data->title', 'Tu prueba gratuita termina pronto')->count());

        $this->travel(3)->days();
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertSame(1, $supplierUser->notifications()->where('data->title', 'Terminó tu prueba gratuita')->count());
        $this->assertSame(1, $a['admin']->notifications()->where('data->title', 'Proveedor no disponible')->count());
        $this->assertSame('trial_expired', $supplier->fresh()->commercial_state);
    }

    public function test_recipients_are_always_authorized(): void
    {
        [$supplier, $supplierUser] = $this->makeSupplier();
        [, $otherUser] = $this->makeSupplier(Supplier::APPROVED, 'Competidor');
        $a = $this->makeTenant();
        $super = User::factory()->superAdmin()->create();
        $notifier = app(Notifier::class);

        $forSupplier = new MarketplaceNotice('Aviso', 'Texto', '/proveedores', 'x', false, $supplier->id);
        $this->assertNotNull($notifier->sendToAccount($supplierUser, $forSupplier));
        $this->assertNull($notifier->sendToAccount($supplierUser, $forSupplier)); // mismo evento: una sola vez
        $this->assertNull($notifier->sendToAccount($otherUser, $forSupplier));   // otro proveedor
        $this->assertNull($notifier->sendToAccount($a['admin'], $forSupplier));  // una empresa
        $this->assertNull($notifier->sendToAccount($super, $forSupplier));       // el SuperAdmin no recibe avisos de proveedores
        $team = new MarketplaceNotice('Equipo', 'Texto', '/admin', 'y', false, null, true);
        $this->assertNull($notifier->sendToAccount($supplierUser, $team));
        $this->assertNotNull($notifier->sendToAccount($super, $team));
        // Un aviso de comprador no llega a un proveedor (no tiene empresa).
        $this->assertNull($notifier->sendTo($supplierUser, new MarketplaceNotice('C', 'T', '/admin', 'z'), (int) $a['company']->id));

        // Cuenta cerrada: no recibe más avisos.
        $supplier->forceFill(['closed_at' => now()])->save();
        $this->assertNull($notifier->sendToAccount($supplierUser, new MarketplaceNotice('Aviso', 'Texto', '/proveedores', 'w', false, $supplier->id)));
    }

    public function test_realtime_push_and_mail_reuse_the_existing_channels(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'clave', 'webpush.vapid.public_key' => 'clave-publica']);
        Event::fake([UserNotificationsChanged::class]);
        Notification::fake();
        [$supplier, $supplierUser] = $this->makeSupplier();
        $supplierUser->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256dh', 'auth');
        $product = $this->makeProduct($supplier);
        $a = $this->makeTenant();

        app(MarketplaceRequests::class)->create($a['admin'], $supplier->id, [$product->id => 1], ['contact_name' => 'Ana', 'contact_email' => 'ana@e.test'], 'k');

        Event::assertDispatched(UserNotificationsChanged::class, fn (UserNotificationsChanged $e) => $e->user->is($supplierUser));
        Notification::assertSentTo($supplierUser, MarketplaceNotice::class, fn ($n, $channels) => $channels === [WebPushChannel::class]);
        $row = $supplierUser->notifications()->sole();
        $this->assertNotNull($row->mailed_at); // correo aceptado por el proveedor de correo (no «leído»)
        $this->assertNull($row->read_at);
    }

    public function test_the_supplier_inbox_is_private_and_marks_as_read(): void
    {
        [, $supplierUser, $a, $request] = $this->quotedRequest();
        [, $otherUser] = $this->makeSupplier(Supplier::APPROVED, 'Competidor');
        $mine = $supplierUser->notifications()->sole();

        $this->actingAs($supplierUser)->get(route('suppliers.notifications.index'))->assertOk()->assertSee('Pedido de cotización nuevo');
        $this->getJson(route('suppliers.notifications.count'))->assertJson(['unread' => 1]);
        $this->get(route('suppliers.notifications.open', $mine->id))->assertRedirect('/proveedores/pedidos/'.$request->reference);
        $this->assertNotNull($mine->fresh()->read_at);

        // El aviso de otro usuario no se abre; las empresas usan su campanita.
        $this->actingAs($otherUser)->get(route('suppliers.notifications.open', $mine->id))->assertNotFound();
        $this->actingAs($a['admin'])->get(route('suppliers.notifications.index'))->assertRedirect();
    }
}
