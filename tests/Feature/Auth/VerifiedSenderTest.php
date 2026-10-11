<?php

namespace Tests\Feature\Auth;

use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Building;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Notifications\QuoteSentNotification;
use App\Services\Quotes\QuoteSender;
use App\Support\VerifiedSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cuentas nuevas sin correo confirmado: no mandan correos a terceros
 * (presupuestos, invitaciones al portal). Las cuentas anteriores, el
 * SuperAdmin y el resto del uso no cambian.
 */
class VerifiedSenderTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        config(['auth.verified_sender_since' => '2026-01-01']);
        $this->a = $this->makeTenant();
        auth()->logout();

        $plan = SubscriptionPlan::findBySlug('empresa');
        Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-'.$this->a['company']->id, 'plan' => 'empresa',
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $this->a['company']->forgetPlan();
    }

    private function admin(bool $verified, string $createdAt): User
    {
        $admin = $this->a['admin'];
        $admin->forceFill(['email_verified_at' => $verified ? now() : null, 'created_at' => $createdAt])->saveQuietly();

        return $admin->fresh();
    }

    private function quote(): Quote
    {
        $this->actingAs($this->a['admin']);
        $quote = Quote::create([
            'building_id' => $this->a['building']->id, 'client_id' => $this->a['building']->client_id, 'created_by' => $this->a['admin']->id,
            'title' => 'Cambio de operador', 'valid_until' => now()->addDays(15)->toDateString(),
        ]);
        $quote->items()->create(['concept' => 'Operador', 'quantity' => 1, 'unit_price' => 250000]);
        $quote->refreshTotal();
        auth()->logout();

        return $quote->fresh();
    }

    public function test_who_has_to_confirm_first(): void
    {
        $this->assertTrue(VerifiedSender::required($this->admin(false, '2026-03-01')));
        $this->assertFalse(VerifiedSender::required($this->admin(true, '2026-03-01')));
        $this->assertFalse(VerifiedSender::required($this->admin(false, '2025-06-01'))); // cuenta anterior
        $this->assertFalse(VerifiedSender::required(null));                             // proceso del sistema
        $this->assertFalse(VerifiedSender::required(User::factory()->create(['is_super_admin' => true, 'email_verified_at' => null])));

        config(['auth.verified_sender_since' => null]); // desactivado
        $this->assertFalse(VerifiedSender::required($this->admin(false, '2026-03-01')));
    }

    public function test_a_new_unconfirmed_account_cannot_email_a_quote_until_it_confirms(): void
    {
        $quote = $this->quote();
        $admin = $this->admin(false, '2026-03-01');

        try {
            app(QuoteSender::class)->send($quote, 'consorcio@cliente.test', null, $admin);
            $this->fail('Debió bloquear el envío');
        } catch (ValidationException $e) {
            $this->assertSame(VerifiedSender::MESSAGE, $e->errors()['email'][0]);
        }
        Notification::assertNothingSent();
        $this->assertSame(Quote::DRAFT, $quote->fresh()->status); // no cambió nada

        $this->assertTrue(app(QuoteSender::class)->send($quote, 'consorcio@cliente.test', null, $this->admin(true, '2026-03-01')));
        Notification::assertSentOnDemand(QuoteSentNotification::class);
    }

    public function test_an_older_unconfirmed_account_keeps_sending(): void
    {
        $this->assertTrue(app(QuoteSender::class)->send($this->quote(), 'consorcio@cliente.test', null, $this->admin(false, '2025-06-01')));
    }

    public function test_portal_invitations_wait_for_the_confirmation(): void
    {
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $this->actingInPanel($this->admin(false, '2026-03-01'));

        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: ['name' => 'Sofía', 'email' => 'sofia@consorcio.test', 'building_ids' => [$building->id]])
            ->assertNotified('Confirmá tu correo');

        $this->assertNull(User::where('email', 'sofia@consorcio.test')->first()); // no se creó nada
        Notification::assertNothingSent();

        $this->actingInPanel($this->admin(true, '2026-03-01'));
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: ['name' => 'Sofía', 'email' => 'sofia@consorcio.test', 'building_ids' => [$building->id]])
            ->assertHasNoTableActionErrors();
        Notification::assertSentTo(User::where('email', 'sofia@consorcio.test')->sole(), PortalInvitationNotification::class);
    }
}
