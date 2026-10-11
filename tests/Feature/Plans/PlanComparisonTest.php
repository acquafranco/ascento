<?php

namespace Tests\Feature\Plans;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La comparativa pública sale de la configuración real de los planes y tiene
 * que coincidir con los planes comerciales definidos.
 */
class PlanComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function matrix(): array
    {
        return collect(SubscriptionPlan::comparisonRows(SubscriptionPlan::offered()))
            ->mapWithKeys(fn ($row) => [$row[0] => $row[1]])->all();
    }

    public function test_the_matrix_matches_the_commercial_plans(): void
    {
        $m = $this->matrix();

        $this->assertSame(['inicial' => 'Hasta 20', 'profesional' => 'Hasta 70', 'empresa' => 'Hasta 300'], $m['Edificios']);
        $this->assertSame(['inicial' => 'Hasta 50', 'profesional' => 'Hasta 150', 'empresa' => 'Hasta 420'], $m['Clientes']);
        $this->assertSame(['inicial' => 'Hasta 3', 'profesional' => 'Hasta 10', 'empresa' => 'Hasta 25'], $m['Usuarios técnicos u operativos']);
        $this->assertSame(['inicial' => 'Hasta 15', 'profesional' => 'Sin límite', 'empresa' => 'Sin límite'], $m['Informes por mes']);

        foreach (['Portal para clientes', 'Video en los informes', 'Presupuestos con PDF y envío por correo', 'Remitos digitales para el cliente (PDF y enlace)'] as $paid) {
            $this->assertSame(['inicial' => false, 'profesional' => true, 'empresa' => true], $m[$paid], $paid);
        }
        foreach (['Mapa de edificios', 'Exportación de los datos de tu empresa', 'Avisos en la app en tiempo real', 'Notificaciones push en el celular', 'Stock, servicios y cobranzas', 'Mantenimientos, inspecciones y agenda mensual'] as $common) {
            $this->assertSame(['inicial' => true, 'profesional' => true, 'empresa' => true], $m[$common], $common);
        }
        $this->assertSame(['inicial' => false, 'profesional' => false, 'empresa' => true], $m['Indicadores avanzados y alertas de tendencias']);
    }

    public function test_the_home_page_sells_real_features_without_invented_claims(): void
    {
        $this->withoutVite();
        $page = $this->get('/')->assertOk();

        $page->assertSee('Comparativa completa')->assertSee('Portal para tus clientes')->assertSee('Avisos en tiempo real')
            ->assertSee('$69.000')->assertSee('$119.000')->assertSee('$169.000')
            ->assertSee(route('register'), false)
            ->assertDontSee('redujimos muchísimo')   // sin testimonios no verificables
            ->assertDontSee('0000-0000')             // sin datos de ejemplo
            ->assertDontSee('href="#" aria-label', false);
    }
}
