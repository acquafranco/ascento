<?php

namespace Tests\Feature\Notifications;

use App\Jobs\NotifyCompanyAdmins;
use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Telegram como canal adicional: vinculación segura (código de un solo uso,
 * webhook con clave secreta) y avisos solo a quien corresponde.
 */
class TelegramTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const SECRET = 'clave-secreta-del-webhook-123';

    private array $a;

    private array $b;

    /** @var list<array> mensajes enviados por el bot */
    private array $sent = [];

    private int $sendStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:ABC',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return match ($method) {
                'getMe' => Http::response(['ok' => true, 'result' => ['username' => 'AscentoAvisosBot', 'first_name' => 'Ascento']]),
                'setWebhook' => Http::response(['ok' => true, 'result' => true]),
                'sendMessage' => $this->recordSend($request),
                default => Http::response(['ok' => false], 404),
            };
        }]);

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function recordSend(Request $request)
    {
        if ($this->sendStatus !== 200) {
            return Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], $this->sendStatus);
        }

        $this->sent[] = $request->data();

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    }

    private function link(User $user, string $chatId): void
    {
        $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => now()])->saveQuietly();
    }

    private function webhook(array $message, ?string $secret = self::SECRET)
    {
        return $this->withHeaders($secret ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : [])
            ->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => $message]);
    }

    private function privateMessage(string $chatId, string $text): array
    {
        return ['chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => $text];
    }

    private function connectToken(User $user): string
    {
        $location = $this->actingAs($user)
            ->get("/{$user->company->slug}/telegram/connect")
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://t.me/AscentoAvisosBot?start=', $location);

        return substr($location, strlen('https://t.me/AscentoAvisosBot?start='));
    }

    /*
    |--------------------------------------------------------------------------
    | VINCULACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_technician_links_telegram_with_a_one_time_code(): void
    {
        $token = $this->connectToken($this->a['technician']);

        $this->webhook($this->privateMessage('5551', "/start {$token}"))->assertOk();

        $technician = $this->a['technician']->fresh();
        $this->assertSame('5551', $technician->telegram_chat_id);
        $this->assertNotNull($technician->telegram_linked_at);
        $this->assertStringContainsString('Listo', $this->sent[0]['text']);
        $this->assertStringContainsString('orden de trabajo', $this->sent[0]['text']);

        // El código ya no sirve para otra persona.
        $this->webhook($this->privateMessage('9999', "/start {$token}"))->assertOk();
        $this->assertSame('5551', $technician->fresh()->telegram_chat_id);
        $this->assertSame(0, User::where('telegram_chat_id', '9999')->count());
        $this->assertStringContainsString('venció', end($this->sent)['text']);
    }

    public function test_the_code_expires(): void
    {
        $token = $this->connectToken($this->a['technician']);
        $this->travel(16)->minutes();

        $this->webhook($this->privateMessage('5551', "/start {$token}"))->assertOk();

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
    }

    public function test_webhook_without_the_secret_is_rejected(): void
    {
        $token = $this->connectToken($this->a['technician']);

        $this->webhook($this->privateMessage('5551', "/start {$token}"), secret: 'otra')->assertUnauthorized();
        $this->webhook($this->privateMessage('5551', "/start {$token}"), secret: null)->assertUnauthorized();

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
        $this->assertSame([], $this->sent);
    }

    public function test_group_chats_are_ignored(): void
    {
        $token = $this->connectToken($this->a['technician']);

        $this->webhook(['chat' => ['id' => -100123, 'type' => 'group'], 'text' => "/start {$token}"])->assertOk();

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
    }

    public function test_stop_unlinks_and_a_chat_belongs_to_one_account(): void
    {
        $this->link($this->a['technician'], '5551');

        // Otro usuario vincula el mismo chat (celular compartido): se lo queda él.
        $token = $this->connectToken($this->a['admin']);
        $this->webhook($this->privateMessage('5551', "/start {$token}"));

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
        $this->assertSame('5551', $this->a['admin']->fresh()->telegram_chat_id);

        $this->webhook($this->privateMessage('5551', '/stop'))->assertOk();
        $this->assertNull($this->a['admin']->fresh()->telegram_chat_id);
    }

    public function test_super_admin_and_guests_cannot_link(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->get("/{$this->a['company']->slug}/telegram/connect")->assertRedirect('/login');

        // El SuperAdmin no pertenece a la empresa: vuelve a su panel, sin link.
        $this->actingAs($super)
            ->get("/{$this->a['company']->slug}/telegram/connect")
            ->assertRedirect(url('/admin'));

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/getMe'));
    }

    public function test_user_can_disconnect_from_the_app(): void
    {
        $this->link($this->a['technician'], '5551');

        $this->actingAs($this->a['technician'])
            ->delete("/{$this->a['company']->slug}/telegram")
            ->assertRedirect();

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
    }

    /*
    |--------------------------------------------------------------------------
    | AVISOS
    |--------------------------------------------------------------------------
    */

    public function test_assigned_technician_gets_the_order_on_telegram_with_a_button(): void
    {
        $this->link($this->a['technician'], '5551');
        $this->link($this->b['technician'], '7777');

        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'notes' => 'Puerta <b>trabada</b>']);
        $workOrder->users()->attach($this->a['technician']->id);

        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertCount(1, $this->sent);
        $message = $this->sent[0];
        $this->assertSame('5551', (string) $message['chat_id']);
        $this->assertSame('HTML', $message['parse_mode']);
        $this->assertStringContainsString('Nueva orden de trabajo', $message['text']);
        // El texto de la base se escapa (no se puede inyectar HTML).
        $this->assertStringContainsString('Puerta &lt;b&gt;trabada&lt;/b&gt;', $message['text']);
        $this->assertSame('Abrir orden', $message['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertStringEndsWith("/{$this->a['company']->slug}/work-orders/{$workOrder->id}", $message['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function test_other_company_and_unassigned_never_get_it(): void
    {
        $this->link($this->b['technician'], '7777');
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->b['technician']->id);

        $this->assertSame([], $this->sent);
    }

    public function test_admin_gets_reports_on_telegram(): void
    {
        $this->link($this->a['admin'], '4441');
        $this->link($this->b['admin'], '4442');

        $report = Report::factory()->create([
            'company_id' => $this->a['company']->id,
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
            'priority' => 'critica',
        ]);

        NotifyCompanyAdmins::dispatchSync(NotifyCompanyAdmins::REPORT, $report->id);

        $this->assertCount(1, $this->sent);
        $this->assertSame('4441', (string) $this->sent[0]['chat_id']);
        $this->assertStringContainsString('Reporte crítico', $this->sent[0]['text']);
        $this->assertSame('Ver reporte', $this->sent[0]['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_blocking_the_bot_unlinks_the_user_without_breaking_anything(): void
    {
        $this->link($this->a['technician'], '5551');
        $this->sendStatus = 403;

        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertNull($this->a['technician']->fresh()->telegram_chat_id);
    }

    public function test_without_bot_token_nothing_is_sent(): void
    {
        config(['services.telegram.bot_token' => null]);
        $this->link($this->a['technician'], '5551');

        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertSame([], $this->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_setup_registers_the_webhook_with_the_secret(): void
    {
        config(['app.url' => 'https://app.ascento.test']);

        $this->artisan('telegram:setup')
            ->expectsOutputToContain('@AscentoAvisosBot')
            ->expectsOutputToContain('https://app.ascento.test/api/telegram/webhook')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://app.ascento.test/api/telegram/webhook'
            && $request['secret_token'] === self::SECRET
            && $request['allowed_updates'] === ['message']);
    }

    public function test_setup_explains_what_is_missing(): void
    {
        config(['services.telegram.webhook_secret' => 'corta']);
        $this->artisan('telegram:setup')->expectsOutputToContain('16 caracteres')->assertFailed();

        config(['services.telegram.bot_token' => null]);
        $this->artisan('telegram:setup')->expectsOutputToContain('TELEGRAM_BOT_TOKEN')->assertFailed();
    }
}
