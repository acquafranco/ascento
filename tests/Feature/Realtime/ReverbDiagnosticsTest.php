<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use App\Support\Diagnostics\ReverbProbe;
use App\Support\Realtime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Tests\TestCase;
use TypeError;

/**
 * Publicar en Reverb: qué ve ascento:check-production y qué queda en el log
 * cuando falla un aviso. Sin servidor real: se simulan las respuestas HTTP.
 */
class ReverbDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'clave-publica-test',
            'broadcasting.connections.reverb.secret' => 'secreto-test',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options' => ['host' => 'ws.ascento.test', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
        ]);
        app(BroadcastManager::class)->purge('reverb');
    }

    /** Respuestas simuladas para el cliente HTTP del broadcaster. */
    private function reverbAnswers(...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $o = config('broadcasting.connections.reverb.options');
        Broadcast::connection('reverb')->setPusher(new Pusher('clave-publica-test', 'secreto-test', '1001', $o, new Client(['handler' => $stack])));
    }

    private function realtimeSection(): string
    {
        Artisan::call('ascento:check-production');
        $out = Artisan::output();

        return substr($out, strpos($out, 'Tiempo real'), 1500);
    }

    public function test_publishing_works_even_when_no_channel_is_occupied(): void
    {
        // Antes: getChannels() con {"channels":[]} → TypeError en la librería
        // de Pusher, aunque Reverb respondía bien (producción, 10-11/10/2026).
        $this->reverbAnswers(new Response(200, [], '{}'));

        $this->assertTrue(ReverbProbe::publish()['ok']);
        $this->assertSame('/apps/1001/events', $this->requests[0]['request']->getUri()->getPath());
        $this->assertStringContainsString(ReverbProbe::CHANNEL, (string) $this->requests[0]['request']->getBody());
    }

    public function test_the_old_check_call_breaks_on_an_empty_channel_list(): void
    {
        // Causa del TypeError de producción: lo que respondía Reverb era correcto.
        $this->reverbAnswers(new Response(200, [], '{"channels":[]}'));

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('get_object_vars()');
        Broadcast::connection('reverb')->getPusher()->getChannels();
    }

    public function test_the_check_reports_success_with_the_target(): void
    {
        $this->reverbAnswers(new Response(200, [], '{}'));

        $section = $this->realtimeSection();

        $this->assertStringContainsString('La app publicó un mensaje de prueba en Reverb (https://ws.ascento.test:443)', $section);
        $this->assertStringNotContainsString('TypeError', $section);
    }

    public function test_a_wrong_secret_is_reported_as_a_rejected_signature(): void
    {
        $this->reverbAnswers(new Response(401, [], 'Authentication signature invalid.'));

        $probe = ReverbProbe::publish();

        $this->assertSame('http', $probe['kind']);
        $this->assertStringContainsString('HTTP 401', $probe['detail']);
        $this->assertStringContainsString('rechazó la firma', $probe['hint']);
    }

    public function test_a_proxy_error_is_reported_without_its_html(): void
    {
        $this->reverbAnswers(new Response(502, [], '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center></body></html>'));

        $probe = ReverbProbe::publish();

        $this->assertSame('http', $probe['kind']);
        $this->assertStringNotContainsString('<', $probe['detail']);
        $this->assertStringContainsString('502 Bad Gateway', $probe['detail']);
        $this->assertStringContainsString('no llega al proceso de Reverb', $probe['hint']);
    }

    public function test_a_network_failure_is_connectivity_and_never_shows_the_signed_url(): void
    {
        $this->reverbAnswers(new ConnectException(
            'cURL error 7: Failed to connect to ws.ascento.test port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://ws.ascento.test/apps/1001/events?auth_key=clave-publica-test&auth_signature=abc123def secreto-test',
            new Request('POST', 'https://ws.ascento.test/apps/1001/events'),
        ));

        $probe = ReverbProbe::publish();

        $this->assertSame('connection', $probe['kind']);
        $this->assertStringContainsString('cURL error 7', $probe['detail']);
        $this->assertStringContainsString('Nada atiende', $probe['hint']);
        foreach (['auth_signature', 'abc123def', 'secreto-test'] as $secret) {
            $this->assertStringNotContainsString($secret, $probe['detail'].$probe['hint']);
        }
    }

    public function test_a_configuration_error_is_not_confused_with_connectivity(): void
    {
        config(['broadcasting.connections.reverb.app_id' => null]); // REVERB_APP_ID faltante
        app(BroadcastManager::class)->purge('reverb');

        $probe = ReverbProbe::publish();
        $this->assertSame('config', $probe['kind']);
        $this->assertStringContainsString('no de red', $probe['hint']);

        $section = $this->realtimeSection();
        $this->assertStringContainsString('La app no pudo armar el cliente de Reverb (configuración, no red)', $section);
        $this->assertStringNotContainsString('no pudo conectarse', $section);
        $this->assertStringNotContainsString('secreto-test', $section);
    }

    public function test_a_failed_realtime_notice_logs_the_cause_without_secrets(): void
    {
        $user = User::factory()->create();
        $this->reverbAnswers(new Response(401, [], 'Authentication signature invalid.'));
        Log::spy();

        Realtime::notificationsChanged($user); // no explota

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'No se pudo emitir el aviso en tiempo real'
            && $context['user_id'] === $user->id
            && $context['kind'] === 'http'
            && str_contains($context['detail'], 'Authentication signature invalid')
            && $context['target'] === 'https://ws.ascento.test:443'
            && ! str_contains(json_encode($context), 'secreto-test'));
    }
}
