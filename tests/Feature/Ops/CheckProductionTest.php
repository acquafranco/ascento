<?php

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El chequeo de producción es de solo lectura y nunca muestra secretos. */
class CheckProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_missing_mail_configuration_without_printing_secrets(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.proveedor.test', 'mail.mailers.smtp.username' => 'usuario', 'mail.mailers.smtp.password' => 'CLAVE-SMTP-SECRETA', 'app.debug' => true]);

        $this->artisan('ascento:check-production')
            ->expectsOutputToContain('APP_DEBUG está activado')
            ->expectsOutputToContain('MAIL_USERNAME y MAIL_PASSWORD cargados (no se muestran)')
            ->doesntExpectOutputToContain('CLAVE-SMTP-SECRETA')
            ->assertFailed();
    }

    public function test_log_mailer_is_flagged_as_not_sending(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('ascento:check-production')->expectsOutputToContain('los correos NO salen')->assertFailed();
    }
}
