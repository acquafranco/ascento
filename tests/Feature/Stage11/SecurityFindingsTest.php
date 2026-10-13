<?php

namespace Tests\Feature\Stage11;

use App\Enums\PermissionArea;
use App\Filament\Pages\BuildingsMap;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11: cada hallazgo confirmado de la auditoría, reproducido (falla
 * sin la corrección) y bloqueado.
 */
class SecurityFindingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => User::ROLE_STAFF]);
        $user->forceFill(['permissions' => $permissions])->save();

        return $user->fresh();
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    /*
    | H1. Mapa: «placeBuilding» es un método de Livewire (no una acción de
    | Filament), así que no pasaba por ActionGuard. Un administrativo con solo
    | «ver» en Edificios podía cambiar la ubicación de cualquier edificio.
    */
    public function test_a_view_only_staff_user_cannot_move_a_building_on_the_map(): void
    {
        $building = $this->a['building'];
        $building->forceFill(['latitude' => -34.6, 'longitude' => -58.4])->saveQuietly();

        $this->inPanel($this->staff([PermissionArea::Buildings->key(PermissionArea::VIEW)]));
        $page = Livewire::test(BuildingsMap::class)->assertOk(); // ver el mapa, sí

        try {
            $page->call('placeBuilding', $building->id, -10.0, -10.0);
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $building->refresh();
        $this->assertEqualsWithDelta(-34.6, (float) $building->latitude, 0.0001);
        $this->assertEqualsWithDelta(-58.4, (float) $building->longitude, 0.0001);
        $this->assertFalse(Livewire::test(BuildingsMap::class)->instance()->canPlaceBuildings()); // ni el botón
    }

    public function test_the_owner_and_managing_staff_still_place_buildings_on_the_map(): void
    {
        $building = $this->a['building'];

        // Con «gestionar», el dueño y el administrativo sí la ubican.
        $this->inPanel($this->staff([PermissionArea::Buildings->key(PermissionArea::MANAGE)]));
        Livewire::test(BuildingsMap::class)->call('placeBuilding', $building->id, -31.4, -64.2);
        $this->assertEqualsWithDelta(-31.4, (float) $building->fresh()->latitude, 0.0001);

        $this->inPanel($this->a['admin']->fresh());
        Livewire::test(BuildingsMap::class)->call('placeBuilding', $building->id, -32.9, -60.6);
        $this->assertEqualsWithDelta(-32.9, (float) $building->fresh()->latitude, 0.0001);
    }

    /*
    | H2. Ingreso: el límite era de 5 intentos por correo + IP. Desde una
    | misma IP se podían probar contraseñas contra cuentas distintas sin freno
    | (password spraying).
    */
    public function test_a_single_ip_cannot_try_passwords_against_many_accounts(): void
    {
        $real = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => 'real@ejemplo.test', 'password' => Hash::make('clave-correcta-123')]);

        // 30 fallos contra 30 cuentas distintas (ninguna llega a 5 por correo).
        for ($i = 0; $i < LoginRequest::IP_MAX_FAILURES; $i++) {
            $this->from('/login')->post('/login', ['email' => "persona{$i}@ejemplo.test", 'password' => 'incorrecta-123']);
        }

        // Desde esa IP ya no se prueba nada más, ni siquiera con la clave correcta.
        $this->from('/login')->post('/login', ['email' => $real->email, 'password' => 'clave-correcta-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));

        // Pasada la ventana, entra.
        $this->travel(LoginRequest::IP_DECAY_SECONDS + 1)->seconds();
        $this->from('/login')->post('/login', ['email' => $real->email, 'password' => 'clave-correcta-123']);
        $this->assertAuthenticatedAs($real);
    }

    public function test_successful_logins_from_one_office_are_never_blocked(): void
    {
        // 40 técnicos que entran bien desde la misma red: los aciertos no cuentan.
        for ($i = 0; $i < 40; $i++) {
            $user = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => "tecnico{$i}@ejemplo.test", 'password' => Hash::make('clave-correcta-123')]);
            $this->post('/login', ['email' => $user->email, 'password' => 'clave-correcta-123']);
            $this->assertAuthenticatedAs($user);
            auth()->logout();
            $this->flushSession();
        }
    }

    /*
    | H3. Sesiones: al cambiar la contraseña, las sesiones abiertas en otros
    | dispositivos se cerraban en el panel (AuthenticateSession de Filament)
    | pero no en la app del técnico ni en el portal.
    */
    public function test_changing_the_password_closes_other_sessions_of_the_technician_app(): void
    {
        $tech = $this->a['technician'];
        $slug = $this->a['company']->slug;

        $this->actingAs($tech);
        $this->get("/{$slug}/dashboard")->assertOk();

        // La contraseña cambia en otro dispositivo (o con «Olvidé mi contraseña»).
        $tech->forceFill(['password' => Hash::make('otra-clave-segura-456')])->save();

        $this->get("/{$slug}/dashboard")->assertRedirect();
        $this->assertGuest();
    }

    public function test_changing_the_password_closes_other_sessions_of_the_portal(): void
    {
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->forceFill(['client_id' => $client->id])->saveQuietly();
        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);

        $portal = $portal->fresh();
        $this->actingAs($portal);
        $this->get('/portal')->assertOk();

        $portal->forceFill(['password' => Hash::make('otra-clave-segura-456')])->save();

        $this->get('/portal')->assertRedirect();
        $this->assertGuest();
    }

    public function test_changing_the_own_password_keeps_the_current_session(): void
    {
        $tech = $this->a['technician'];
        $tech->forceFill(['password' => Hash::make('clave-actual-123')])->save();
        $slug = $this->a['company']->slug;

        $this->actingAs($tech->fresh());
        $this->get("/{$slug}/profile")->assertOk();

        $this->from("/{$slug}/profile")->put('/password', [
            'current_password' => 'clave-actual-123',
            'password' => 'clave-nueva-segura-789',
            'password_confirmation' => 'clave-nueva-segura-789',
        ])->assertSessionHasNoErrors();

        // Quien la cambió sigue adentro en este dispositivo.
        $this->get("/{$slug}/profile")->assertOk();
        $this->assertAuthenticatedAs($tech);
    }

    /*
    | H4. Reportes (fotos y video) y remitos (firmas de hasta 1 MB) se
    | procesan en el servidor y no tenían tope de pedidos: con reportes sin
    | límite mensual, una cuenta comprometida podía saturar el servidor.
    */
    public function test_heavy_technician_submissions_have_a_rate_limit(): void
    {
        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['technician']);

        foreach (['reports.store', 'delivery-notes.store'] as $name) {
            $statuses = [];
            for ($i = 0; $i < 21; $i++) {
                $statuses[] = $this->post(route($name, ['company' => $slug]), [])->getStatusCode();
            }
            $this->assertNotContains(429, array_slice($statuses, 0, 20), "{$name}: el uso normal no se frena");
            $this->assertSame(429, end($statuses), "{$name}: sin tope de pedidos");
        }
    }

    /*
    | H5. Los «throttle:N,M» sin prefijo comparten UN contador por usuario (o
    | por IP sin sesión): consultar el contador de avisos (60/min) agotaba el
    | cupo de otra ruta con tope más bajo, como probar las notificaciones (3/min).
    */
    public function test_each_rate_limited_route_has_its_own_counter(): void
    {
        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['technician']);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson(route('notifications.count'))->assertOk();
        }
        // Ninguna prueba de notificación se pidió todavía: no puede estar agotada.
        $this->assertNotSame(429, $this->postJson(route('push-subscriptions.test', ['company' => $slug]))->getStatusCode());

        // Sin sesión: escanear QR (60/min por IP) no bloquea «Olvidé mi contraseña» (6/min).
        auth()->logout();
        $this->flushSession();
        for ($i = 0; $i < 10; $i++) {
            $this->get('/qr/'.str_repeat('x', 48));
        }
        $this->assertNotSame(429, $this->post('/forgot-password', ['email' => 'alguien@ejemplo.test'])->getStatusCode());
    }

    /*
    | B1. Conexión de WhatsApp por empresa (en desuso, pero con las rutas
    | activas): el token quedaba visible al serializar la empresa y el log
    | guardaba la lista de negocios, cuentas y teléfonos de Meta del cliente.
    */
    public function test_whatsapp_credentials_and_meta_data_never_reach_arrays_or_logs(): void
    {
        $company = $this->a['company'];
        $company->forceFill(['whatsapp_access_token' => 'TOKEN-SECRETO-DE-META'])->save();
        $this->assertArrayNotHasKey('whatsapp_access_token', $company->fresh()->toArray());
        $this->assertStringNotContainsString('TOKEN-SECRETO-DE-META', $company->fresh()->toJson());

        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'TOKEN-NUEVO']),
            'graph.facebook.com/*/me/businesses' => Http::response(['data' => [['id' => '111', 'name' => 'Ascensores Privados SA']]]),
            'graph.facebook.com/*/owned_whatsapp_business_accounts' => Http::response(['data' => [['id' => '222', 'name' => 'Cuenta privada']]]),
            'graph.facebook.com/*/phone_numbers' => Http::response(['data' => [['id' => '333', 'display_phone_number' => '+54 9 11 5555-0000']]]),
        ]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });

        $state = encrypt(['company_id' => $company->id, 'user_id' => $this->a['admin']->id]);
        $this->actingAs($this->a['admin'])->get('/whatsapp/callback?code=1&state='.urlencode($state))->assertRedirect();

        $all = implode("\n", $logged);
        $this->assertNotSame('', $all);
        foreach (['Ascensores Privados SA', 'Cuenta privada', '5555-0000', 'TOKEN-NUEVO'] as $private) {
            $this->assertStringNotContainsString($private, $all, $private);
        }
    }
}
