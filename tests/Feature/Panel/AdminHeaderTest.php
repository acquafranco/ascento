<?php

namespace Tests\Feature\Panel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Encabezado del admin: notificaciones, ayuda y el perfil al extremo derecho. */
class AdminHeaderTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_help_sits_between_notifications_and_the_profile_menu(): void
    {
        $a = $this->makeTenant();
        $html = $this->actingInPanel($a['admin'])->get('/admin/dashboard')->assertOk()->getContent();

        $end = substr($html, strpos($html, 'fi-topbar-end'));
        $bell = strpos($end, 'fi-topbar-database-notifications');
        $help = strpos($end, 'data-ascento-help');
        $user = strpos($end, 'fi-user-menu');

        $this->assertNotFalse($bell);
        $this->assertNotFalse($help);
        $this->assertNotFalse($user);
        $this->assertTrue($bell < $help && $help < $user, 'Orden esperado: notificaciones, ayuda, perfil');
    }
}
