<?php

namespace Tests\Feature\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Marketplace\Concerns\MakesMarketplace;
use Tests\TestCase;

/**
 * Cobros a proveedores con Mercado Pago (API simulada): la suscripción y los
 * destacados se activan SOLO con lo que confirma la API, por el importe y la
 * referencia que creó Ascento; los avisos repetidos o fuera de orden no
 * duplican ni retroceden nada. Las suscripciones de empresas no se mezclan.
 */
class SupplierBillingTest extends TestCase
{
    use InteractsWithTenants, MakesMarketplace, RefreshDatabase;

    private const SECRET = 'secreto-webhook-proveedores';

    /** @var array<string, array> path => respuesta de la API simulada */
    private array $api = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.access_token' => 'APP_USR-TEST', 'services.mercadopago.webhook_secret' => self::SECRET, 'services.mercadopago.test_payer_email' => null]);
        MarketplaceSettings::current()->update(['premium_enabled' => true, 'premium_price' => 25000, 'featured_enabled' => true, 'featured_price_7' => 5000, 'featured_price_30' => 15000]);

        Http::preventStrayRequests();
        Http::fake(['api.mercadopago.com/*' => function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/preapproval') {
                $this->api['created_preapprovals'][] = $request->data();
                $id = 'PRE'.count($this->api['created_preapprovals']);

                return Http::response(['id' => $id, 'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id='.$id], 201);
            }
            if ($request->method() === 'PUT' && str_starts_with($path, '/preapproval/')) {
                $this->api['/preapproval/'.basename($path)]['status'] = $request->data()['status'] ?? 'authorized';

                return Http::response($this->api['/preapproval/'.basename($path)] ?? []);
            }
            if ($request->method() === 'POST' && $path === '/checkout/preferences') {
                $this->api['preferences'][] = $request->data();

                return Http::response(['id' => 'PREF'.count($this->api['preferences']), 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF'.count($this->api['preferences'])], 201);
            }
            if ($path === '/v1/payments/search') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['results' => array_values(array_filter($this->api['payments'] ?? [], fn ($p) => ($p['external_reference'] ?? null) === ($query['external_reference'] ?? '')))]);
            }
            if ($path === '/authorized_payments/search') {
                return Http::response(['results' => []]);
            }

            return isset($this->api[$path]) ? Http::response($this->api[$path]) : Http::response(['message' => 'not found'], 404);
        }]);
    }

    private function webhook(string $type, string $id)
    {
        $requestId = 'req-'.uniqid();
        $ts = (string) now()->getTimestampMs();
        $manifest = 'id:'.(ctype_alnum($id) ? strtolower($id) : $id).";request-id:{$requestId};ts:{$ts};";

        return $this->withHeaders(['x-request-id' => $requestId, 'x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, self::SECRET)])
            ->postJson("/api/mercadopago/webhook?data.id={$id}&type={$type}", ['type' => $type, 'data' => ['id' => $id]]);
    }

    private function preapproval(string $id, Supplier $supplier, string $status, float $amount = 25000): void
    {
        $this->api["/preapproval/{$id}"] = ['id' => $id, 'status' => $status, 'external_reference' => 'ascento-supplier-'.$supplier->id,
            'next_payment_date' => now()->addMonth()->toIso8601String(), 'auto_recurring' => ['transaction_amount' => $amount, 'currency_id' => 'ARS']];
    }

    private function installment(string $id, string $preapprovalId, Supplier $supplier, string $status, float $amount = 25000): void
    {
        $this->api["/authorized_payments/{$id}"] = ['id' => $id, 'preapproval_id' => $preapprovalId, 'external_reference' => 'ascento-supplier-'.$supplier->id,
            'transaction_amount' => $amount, 'currency_id' => 'ARS', 'payment' => ['id' => 'P'.$id, 'status' => $status]];
        $this->api["/v1/payments/P{$id}"] = ['id' => 'P'.$id, 'status' => $status, 'status_detail' => $status === 'approved' ? 'accredited' : 'cc_rejected_other_reason',
            'transaction_amount' => $amount, 'currency_id' => 'ARS', 'date_approved' => $status === 'approved' ? now()->toIso8601String() : null];
    }

    public function test_the_subscription_activates_only_with_a_confirmed_charge_and_never_twice(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $this->actingAs($user);

        // El importe sale de la configuración (lo que mande el navegador se ignora); dos clics, un checkout.
        $this->post(route('suppliers.billing.subscribe'), ['amount' => 1])->assertRedirect('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE1');
        $this->post(route('suppliers.billing.subscribe'))->assertRedirect('https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=PRE1');
        $this->assertCount(1, $this->api['created_preapprovals']);
        $this->assertSame([25000.0, 'ARS', 'ascento-supplier-'.$supplier->id], [$this->api['created_preapprovals'][0]['auto_recurring']['transaction_amount'], $this->api['created_preapprovals'][0]['auto_recurring']['currency_id'], $this->api['created_preapprovals'][0]['external_reference']]);

        // Volver de Mercado Pago no activa nada: se consulta la API (todavía pendiente).
        $this->preapproval('PRE1', $supplier, 'pending');
        $this->get(route('suppliers.billing', ['mp' => 'return', 'status' => 'approved', 'collection_status' => 'approved']))->assertOk();
        $this->assertSame(SupplierStanding::FREE, $supplier->fresh()->standing());

        // Autorizada sin cobro confirmado: sigue pendiente de pago.
        $this->preapproval('PRE1', $supplier, 'authorized');
        $this->webhook('subscription_preapproval', 'PRE1')->assertOk();
        $this->assertSame(SupplierStanding::FREE, $supplier->fresh()->standing());

        // Cobro aprobado: activa un mes. El mismo aviso repetido no suma otro mes.
        $this->installment('AP1', 'PRE1', $supplier, 'approved');
        $this->webhook('subscription_authorized_payment', 'AP1')->assertOk()->assertJson(['status' => 'payment_approved']);
        $end = SupplierSubscription::sole()->current_period_end;
        $this->webhook('subscription_authorized_payment', 'AP1')->assertOk();
        $this->assertTrue(SupplierSubscription::sole()->current_period_end->equalTo($end));
        $this->assertSame(SupplierStanding::PREMIUM, $supplier->fresh()->standing());
        $this->assertSame(1, SupplierPayment::where('status', SupplierPayment::APPROVED)->count());
        $this->assertSame(1, $user->notifications()->where('data->title', 'Cobro de Premium aprobado')->count());

        // Un aviso viejo «pendiente» no retrocede un cobro aprobado.
        $this->api['/v1/payments/PAP1']['status'] = 'in_process';
        $this->webhook('subscription_authorized_payment', 'AP1')->assertOk();
        $this->assertSame(SupplierPayment::APPROVED, SupplierPayment::sole()->status);
    }

    public function test_wrong_amounts_or_references_never_activate_and_the_team_is_told(): void
    {
        $super = User::factory()->superAdmin()->create();
        [$supplier, $user] = $this->makeSupplier();
        [$intruder] = $this->makeSupplier(Supplier::APPROVED, 'Otro Proveedor');
        $this->actingAs($user)->post(route('suppliers.billing.subscribe'));
        auth()->logout();

        $this->installment('AP9', 'PRE1', $supplier, 'approved', 100);
        $this->webhook('subscription_authorized_payment', 'AP9')->assertOk()->assertJson(['status' => 'payment_amount_mismatch']);
        $this->assertNull(SupplierSubscription::sole()->current_period_end);
        $this->assertSame(1, $super->notifications()->where('data->title', 'Cobro de proveedor con importe distinto')->count());

        // Una cuota con la referencia de otro proveedor no se aplica.
        $this->installment('AP10', 'PRE1', $supplier, 'approved');
        $this->api['/authorized_payments/AP10']['external_reference'] = 'ascento-supplier-'.$intruder->id;
        $this->webhook('subscription_authorized_payment', 'AP10')->assertOk()->assertJson(['status' => 'reference_mismatch']);
        $this->assertNull(SupplierSubscription::sole()->current_period_end);

        // Sin firma válida no se procesa nada.
        $this->withHeaders(['x-signature' => 'ts=1,v1=falsa', 'x-request-id' => 'r'])->postJson('/api/mercadopago/webhook?data.id=AP10&type=payment', [])->assertStatus(401);
    }

    public function test_rejected_charges_and_cancellation_follow_the_gateway(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $this->actingAs($user)->post(route('suppliers.billing.subscribe'));
        $this->preapproval('PRE1', $supplier, 'authorized');
        $this->installment('AP1', 'PRE1', $supplier, 'approved');
        $this->webhook('subscription_authorized_payment', 'AP1');

        // Un mes después rechazan el cobro: queda «cobro rechazado» con tolerancia, y se avisa.
        $this->travel(32)->days();
        $this->installment('AP2', 'PRE1', $supplier, 'rejected');
        $this->webhook('subscription_authorized_payment', 'AP2')->assertOk();
        $this->assertSame(SupplierSubscription::PAST_DUE, SupplierSubscription::sole()->status);
        $this->assertSame(SupplierStanding::PREMIUM, $supplier->fresh()->standing()); // tolerancia de 5 días
        $this->assertSame(1000, SupplierStanding::publicationLimit($supplier->fresh()));
        $this->assertSame(1, $user->notifications()->where('data->title', 'Cobro de Premium rechazado')->count());
        $this->travel(5)->days();
        $this->assertSame(SupplierStanding::FREE, $supplier->fresh()->standing()); // vuelve al plan gratuito, no queda bloqueado
        $this->assertSame(20, SupplierStanding::publicationLimit($supplier->fresh()));

        // Cancelar: se cancela en Mercado Pago y queda cancelada (no se reactiva por un aviso viejo).
        $this->post(route('suppliers.billing.cancel'))->assertSessionHasNoErrors();
        $this->assertSame(SupplierSubscription::CANCELED, SupplierSubscription::sole()->status);
        $this->api['/preapproval/PRE1']['status'] = 'authorized';
        $this->webhook('subscription_preapproval', 'PRE1');
        $this->assertSame(SupplierSubscription::CANCELED, SupplierSubscription::sole()->status);
    }

    public function test_featured_listings_activate_only_after_payment_and_end_when_not_eligible(): void
    {
        $super = User::factory()->superAdmin()->create();
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier, ['name' => 'Operador de puerta']);
        $this->actingAs($user);

        // Precio de la configuración, no del navegador; dos clics, un solo checkout.
        $this->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 7, 'precio' => 1])->assertRedirect();
        $this->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 7])->assertRedirect();
        $this->assertCount(1, $this->api['preferences']);
        $this->assertSame(5000.0, $this->api['preferences'][0]['items'][0]['unit_price']);
        $placement = FeaturedPlacement::sole();
        $this->assertSame(FeaturedPlacement::PENDING_PAYMENT, $placement->status);
        $this->assertFalse($product->fresh()->isFeatured());
        $this->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 14])->assertSessionHasErrors('dias');

        // Pago aprobado (aviso «payment»): se activa una vez; repetido, no duplica.
        $this->api['payments'][] = $this->api['/v1/payments/900'] = ['id' => 900, 'status' => 'approved', 'external_reference' => $placement->external_reference,
            'transaction_amount' => 5000, 'currency_id' => 'ARS', 'date_approved' => now()->toIso8601String()];
        $this->webhook('payment', '900')->assertOk()->assertJson(['status' => 'featured_approved']);
        $this->webhook('payment', '900')->assertOk();
        $placement->refresh();
        $this->assertSame(FeaturedPlacement::ACTIVE, $placement->status);
        $this->assertTrue($placement->ends_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertTrue($product->fresh()->isFeatured());
        $this->assertSame(1, SupplierPayment::where('kind', 'featured')->count());

        // Renovar: el período nuevo empieza al terminar el vigente.
        $this->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 30]);
        $renewal = FeaturedPlacement::latest('id')->first();
        $this->api['payments'][] = ['id' => 901, 'status' => 'approved', 'external_reference' => $renewal->external_reference, 'transaction_amount' => 15000, 'currency_id' => 'ARS'];
        Artisan::call('marketplace:reconcile-payments'); // sin webhook: lo encuentra la reconciliación
        $this->assertTrue($renewal->fresh()->starts_at->equalTo($placement->ends_at));

        // Pago de un producto que dejó de ser elegible: no se activa, queda para revisar.
        $other = $this->makeProduct($supplier, ['name' => 'Botonera']);
        $this->post(route('suppliers.featured.purchase'), ['producto' => $other->id, 'dias' => 7]);
        $late = FeaturedPlacement::latest('id')->first();
        $other->forceFill(['status' => 'paused'])->save();
        $this->api['/v1/payments/902'] = ['id' => 902, 'status' => 'approved', 'external_reference' => $late->external_reference, 'transaction_amount' => 5000, 'currency_id' => 'ARS'];
        $this->webhook('payment', '902')->assertOk();
        $this->assertSame(FeaturedPlacement::NEEDS_REVIEW, $late->fresh()->status);
        $this->assertSame(1, $super->notifications()->where('data->title', 'Destacado pagado que requiere revisión')->count());

        // Devolución: deja de mostrarse.
        $this->api['/v1/payments/900']['status'] = 'refunded';
        $this->webhook('payment', '900')->assertOk();
        $this->assertSame(FeaturedPlacement::CANCELLED, $placement->fresh()->status);

        // Pagos de otras integraciones (o cuotas de suscripciones) no se tocan.
        $this->api['/v1/payments/903'] = ['id' => 903, 'status' => 'approved', 'external_reference' => 'ascento-company-1', 'transaction_amount' => 5000, 'currency_id' => 'ARS'];
        $this->webhook('payment', '903')->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_featured_listings_expire_once_and_can_not_be_bought_by_ineligible_suppliers(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $product = $this->makeProduct($supplier);
        $this->actingAs($user)->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 7]);
        $placement = FeaturedPlacement::sole();
        $this->api['/v1/payments/77'] = ['id' => 77, 'status' => 'approved', 'external_reference' => $placement->external_reference, 'transaction_amount' => 5000, 'currency_id' => 'ARS'];
        $this->webhook('payment', '77');

        $this->travelTo(now()->addDays(6)->addHours(12));
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertSame(1, $user->notifications()->where('data->title', 'Tu destacado vence mañana')->count());
        $this->travel(1)->days();
        Artisan::call('marketplace:tick');
        Artisan::call('marketplace:tick');
        $this->assertSame(FeaturedPlacement::EXPIRED, $placement->fresh()->status);
        $this->assertFalse($product->fresh()->isFeatured());
        $this->assertSame(1, $user->notifications()->where('data->title', 'Destacado vencido')->count());

        // Suspendido por moderación: no puede comprar destacados (pagar no lo habilita).
        $supplier->forceFill(['status' => Supplier::SUSPENDED])->save();
        $this->actingAs($user->fresh())->post(route('suppliers.featured.purchase'), ['producto' => $product->id, 'dias' => 7])->assertForbidden();
        $this->assertSame(1, FeaturedPlacement::count());
    }

    public function test_company_subscriptions_are_not_mixed_with_suppliers(): void
    {
        [$supplier, $user] = $this->makeSupplier();
        $a = $this->makeTenant();
        // Un preapproval de proveedor no toca suscripciones de empresas, y viceversa.
        $this->actingAs($user)->post(route('suppliers.billing.subscribe'));
        auth()->logout();
        $this->preapproval('PRE1', $supplier, 'authorized');
        $this->webhook('subscription_preapproval', 'PRE1')->assertOk()->assertJson(['status' => 'preapproval_authorized']);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(SupplierSubscription::AUTHORIZED, SupplierSubscription::sole()->status);
        $this->assertSame(null, $a['company']->fresh()->subscriptions()->first());
    }
}
