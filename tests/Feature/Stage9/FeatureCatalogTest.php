<?php

namespace Tests\Feature\Stage9;

use App\Enums\PlanFeature;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\IndicatorsPage;
use App\Filament\Pages\PlanFeatures;
use App\Filament\Resources\Elevators\Pages\ListElevators;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Models\Client;
use App\Models\PlanFeatureChange;
use App\Models\Quote;
use App\Models\Report;
use App\Models\ReportVideo;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Plans\PlanFeatureManager;
use App\Services\Reports\ReportVideoService;
use App\Support\Plans\FeatureCatalog;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 9.2: catálogo completo de funcionalidades en «Planes». Una sola
 * definición (FeatureCatalog) que cubre cada PlanFeature; esenciales
 * protegidas; distribución comercial sin cambios; historial de cambios; y
 * cada punto de entrada de mapa, presupuestos y video respetando el plan,
 * sin borrar datos y recuperándolos al volver a habilitar la función.
 */
class FeatureCatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const BASE = ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'digital_delivery_notes',
        'agenda', 'attention_center', 'elevator_file', 'indicators', 'tasks', 'public_qr', 'parachute_tests', 'repairs',
        // Guardias técnicas: en los tres planes, sin costo adicional.
        'on_call'];

    private const PROFESIONAL = ['quotes', 'attention_advanced', 'company_indicators', 'elevator_history_advanced', 'failure_analysis', 'client_portal', 'report_videos', 'client_contacts',
        // Pedido de ayuda por atrapamiento desde el QR: Profesional y Empresa.
        'emergency_requests'];

    private const EMPRESA = ['advanced_indicators', 'advanced_alerts', 'work_photos'];

    private array $a;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->super = User::factory()->superAdmin()->create();
    }

    private function inPanel(User $user, ?int $company = null): void
    {
        $this->flushSession();
        $this->actingAs($user);
        if ($company) {
            session(['selected_company_id' => $company]);
        }
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function onPlan(string $slug): void
    {
        $this->giveCompanyPlan($this->a['company'], $slug);
        $this->a['company']->refresh();
        $this->inPanel($this->a['admin']->fresh());
    }

    /** El SuperAdmin quita (o devuelve) una función de un plan. */
    private function setFeature(string $slug, PlanFeature $feature, bool $on): void
    {
        $plan = SubscriptionPlan::query()->where('slug', $slug)->sole();
        $enabled = collect(PlanFeature::toggleable())->filter(fn ($f) => $plan->allows($f))->map->value->all();
        $enabled = $on ? [...$enabled, $feature->value] : array_values(array_diff($enabled, [$feature->value]));
        $this->inPanel($this->super);
        app(PlanFeatureManager::class)->update($this->super, $plan, $enabled, 'Prueba');
        auth()->logout();
    }

    /* --------------------------------------------------------- CATÁLOGO */

    public function test_the_catalog_covers_every_feature_once_with_module_origin_and_category(): void
    {
        $entries = collect(FeatureCatalog::entries());

        // Cada PlanFeature, exactamente una vez.
        $withFeature = $entries->whereNotNull('feature')->map(fn ($e) => $e['feature']->value)->values();
        $this->assertEqualsCanonicalizing(array_map(fn ($f) => $f->value, PlanFeature::cases()), $withFeature->all());
        $this->assertSame($withFeature->count(), $withFeature->unique()->count());
        $this->assertSame($entries->count(), $entries->pluck('key')->unique()->count());

        foreach ($entries as $e) {
            $this->assertArrayHasKey($e['module'], FeatureCatalog::MODULES, $e['key']);
            $this->assertArrayHasKey($e['category'], FeatureCatalog::CATEGORIES, $e['key']);
            $this->assertNotSame('', trim($e['name']), $e['key']);
            $this->assertNotSame('', trim($e['description']), $e['key']);
            $this->assertNotSame('', trim($e['origin']), $e['key']);
            // La categoría de una función con clave de plan sale de PlanFeature (una sola regla).
            if ($e['feature']) {
                $this->assertSame($e['feature']->isEssential() ? FeatureCatalog::ESSENTIAL : FeatureCatalog::CONFIGURABLE, $e['category'], $e['key']);
            }
        }

        // También las que no se controlaban por plan (antiguas y nuevas).
        foreach (['stock', 'services_receivables', 'company_export', 'staff_users', 'push', 'telegram', 'technician_photo', 'portal_responsibles', 'controller_brand', 'signed_delivery_note', 'platform_backups'] as $key) {
            $this->assertTrue($entries->contains('key', $key), $key);
        }

        // Esenciales según lo aprobado.
        foreach ([PlanFeature::DigitalDeliveryNotes, PlanFeature::Buildings, PlanFeature::Clients, PlanFeature::Technicians, PlanFeature::Maintenances, PlanFeature::Inspections, PlanFeature::WorkOrders, PlanFeature::Indicators, PlanFeature::ElevatorFile] as $essential) {
            $this->assertTrue($essential->isEssential(), $essential->value);
            $this->assertNotContains($essential, PlanFeature::toggleable());
        }
        foreach ([PlanFeature::Map, PlanFeature::Quotes, PlanFeature::ReportVideos, PlanFeature::ElevatorHistoryAdvanced, PlanFeature::FailureAnalysis] as $configurable) {
            $this->assertContains($configurable, PlanFeature::toggleable(), $configurable->value);
        }
    }

    public function test_the_commercial_matrix_prices_and_limits_did_not_change(): void
    {
        $plans = SubscriptionPlan::query()->get()->keyBy('slug');
        $this->assertEqualsCanonicalizing(self::BASE, $plans['inicial']->feature_keys);
        $this->assertEqualsCanonicalizing([...self::BASE, ...self::PROFESIONAL], $plans['profesional']->feature_keys);
        $this->assertEqualsCanonicalizing([...self::BASE, ...self::PROFESIONAL, ...self::EMPRESA], $plans['empresa']->feature_keys);

        $this->assertSame(['69000.00', '119000.00', '169000.00'], [$plans['inicial']->price, $plans['profesional']->price, $plans['empresa']->price]);
        $limits = fn ($p) => [$p->max_buildings, $p->max_clients, $p->max_technicians, $p->max_reports_per_month, $p->max_admin_users];
        $this->assertSame([[20, 50, 3, 15, 0], [120, 150, 10, null, 1], [400, 420, 25, null, 5]], [$limits($plans['inicial']), $limits($plans['profesional']), $limits($plans['empresa'])]);
    }

    public function test_the_catalog_page_shows_everything_and_filters(): void
    {
        $this->inPanel($this->super);
        $html = $this->get('/admin/planes')->assertOk()->getContent();
        foreach (FeatureCatalog::entries() as $e) {
            $this->assertStringContainsString('data-entry="'.$e['key'].'"', $html, $e['key']);
        }
        foreach (FeatureCatalog::MODULES as $label) {
            $this->assertStringContainsString(e($label), $html);
        }
        $this->assertStringContainsString('data-locked="inicial.digital_delivery_notes"', $html);
        $this->assertStringNotContainsString('data-feature="inicial.digital_delivery_notes"', $html);
        $this->assertStringContainsString('data-feature="inicial.map"', $html);
        $this->assertStringContainsString('$69.000', $html);

        // Búsqueda y filtros.
        $page = Livewire::test(PlanFeatures::class);
        $this->assertSame(['quotes'], collect($page->set('search', 'presupuestos numerados')->instance()->rows())->flatten(1)->pluck('key')->all());
        $page->set('search', '')->set('module', 'portal');
        $this->assertSame(['client_portal', 'portal_responsibles'], collect($page->instance()->rows())->flatten(1)->pluck('key')->all());
        $page->set('module', '')->set('category', FeatureCatalog::ROLE);
        $this->assertTrue(collect($page->instance()->rows())->flatten(1)->every(fn ($e) => $e['category'] === FeatureCatalog::ROLE));
        $page->set('category', '')->set('plan', 'inicial:0');
        $notInInicial = collect($page->instance()->rows())->flatten(1)->pluck('key')->all();
        $this->assertContains('quotes', $notInInicial);
        $this->assertNotContains('map', $notInInicial);
        $this->assertContains('portal_responsibles', $notInInicial); // va con el portal
    }

    /* ------------------------------------------- ESENCIALES E HISTORIAL */

    public function test_essentials_cannot_be_disabled_by_the_page_or_a_manipulated_request(): void
    {
        $this->inPanel($this->super);
        foreach (['inicial', 'profesional', 'empresa'] as $slug) {
            app(PlanFeatureManager::class)->update($this->super, SubscriptionPlan::query()->where('slug', $slug)->sole(), ['map', 'inventada']);
            $plan = SubscriptionPlan::query()->where('slug', $slug)->sole();
            foreach (PlanFeature::structural() as $essential) {
                $this->assertTrue($plan->allows($essential), "{$slug}: {$essential->value}");
            }
            $this->assertNotContains('inventada', $plan->feature_keys);
        }

        Livewire::test(PlanFeatures::class)
            ->set('features.inicial.digital_delivery_notes', false)
            ->set('features.inicial.indicators', false)
            ->set('features.inicial.elevator_file', false)
            ->callAction('save');
        $inicial = SubscriptionPlan::query()->where('slug', 'inicial')->sole();
        foreach ([PlanFeature::DigitalDeliveryNotes, PlanFeature::Indicators, PlanFeature::ElevatorFile] as $f) {
            $this->assertTrue($inicial->allows($f), $f->value);
        }
        $this->assertSame(0, PlanFeatureChange::query()->whereIn('feature', ['digital_delivery_notes', 'indicators', 'elevator_file'])->count());
    }

    public function test_every_change_is_recorded_and_only_the_super_admin_sees_it(): void
    {
        $this->inPanel($this->super);
        Livewire::test(PlanFeatures::class)
            ->set('features.inicial.map', false)
            ->set('features.inicial.quotes', true)
            ->callAction('save', ['context' => 'Prueba comercial de octubre']);

        $changes = PlanFeatureChange::query()->orderBy('feature')->get();
        $this->assertSame(['map', 'quotes'], $changes->pluck('feature')->all());
        $map = $changes->firstWhere('feature', 'map');
        $this->assertSame([$this->super->id, $this->super->name, 'inicial', true, false, 'Prueba comercial de octubre'],
            [$map->user_id, $map->user_name, $map->plan_slug, $map->enabled_before, $map->enabled_after, $map->context]);
        $this->assertNotNull($map->created_at);
        $this->assertSame([false, true], [$changes[1]->enabled_before, $changes[1]->enabled_after]);
        $this->get('/admin/planes')->assertOk()->assertSee('Prueba comercial de octubre')->assertSee('Mapa de edificios');

        // Guardar sin cambios no agrega filas.
        Livewire::test(PlanFeatures::class)->callAction('save');
        $this->assertSame(2, PlanFeatureChange::count());

        // No se modifica ni se borra.
        try {
            $map->forceFill(['context' => 'otro'])->save();
            $this->fail('El historial no se modifica.');
        } catch (LogicException) {
        }
        try {
            $map->delete();
            $this->fail('El historial no se borra.');
        } catch (LogicException) {
        }

        // El dueño de una empresa no lo ve ni cambia planes.
        $this->inPanel($this->a['admin']);
        $this->get('/admin/planes')->assertForbidden();
        try {
            app(PlanFeatureManager::class)->update($this->a['admin'], SubscriptionPlan::query()->where('slug', 'inicial')->sole(), []);
            $this->fail('Solo el SuperAdmin.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(2, PlanFeatureChange::count());
    }

    /* ---------------------------------------------- PUNTOS DE ENTRADA */

    public function test_map_follows_the_plan_and_comes_back(): void
    {
        $this->onPlan('inicial');
        $open = Livewire::test(BuildingsMap::class)->assertNoRedirect();
        request()->headers->set('X-Livewire', '1');
        $open->call('pollNewMarkers')->assertOk(); // con la función, el mismo pedido funciona
        request()->headers->remove('X-Livewire');

        $this->setFeature('inicial', PlanFeature::Map, false);
        $this->onPlan('inicial');
        Livewire::test(BuildingsMap::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::Map));
        // Un pedido siguiente de Livewire sobre la página que ya estaba abierta (pedir marcadores nuevos) responde 403.
        request()->headers->set('X-Livewire', '1');
        $open->call('pollNewMarkers')->assertForbidden();
        request()->headers->remove('X-Livewire');
        // El link a «ver en el mapa» de la ficha del edificio desaparece, y los edificios no se tocan.
        $this->assertFalse(PlanGuard::currentAllows(PlanFeature::Map));
        $this->assertNotNull($this->a['building']->fresh());

        $this->setFeature('inicial', PlanFeature::Map, true);
        $this->onPlan('inicial');
        Livewire::test(BuildingsMap::class)->assertNoRedirect();
    }

    public function test_basic_indicators_and_the_elevator_file_are_available_on_every_plan(): void
    {
        foreach (['inicial', 'profesional', 'empresa'] as $slug) {
            $this->onPlan($slug);
            Livewire::test(IndicatorsPage::class)->assertNoRedirect();
            Livewire::test(ListElevators::class)->assertNoRedirect();
        }
    }

    public function test_quotes_follow_the_plan_but_links_already_sent_keep_working(): void
    {
        $this->onPlan('profesional');
        $quote = Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'status' => Quote::SENT]);
        $publicUrl = $quote->signedPublicUrl();
        $this->assertSame('application/pdf', $this->get(route('quotes.pdf', $quote))->assertOk()->headers->get('Content-Type'));

        $this->onPlan('inicial');
        // Panel: pantallas, servidor y la ruta privada del PDF, cerrados.
        Livewire::test(ListQuotes::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::Quotes));
        $this->assertNotSame('application/pdf', $this->get(route('quotes.pdf', $quote))->headers->get('Content-Type'));
        try {
            PlanGuard::ensureCurrent(PlanFeature::Quotes);
            $this->fail('Sin presupuestos en el plan.');
        } catch (PlanFeatureUnavailableException) {
        }

        // El enlace que ya recibió el cliente sigue abriendo, y el presupuesto se conserva.
        auth()->logout();
        $this->flushSession();
        $this->get($publicUrl)->assertOk();
        $this->assertNotNull(Quote::withoutGlobalScopes()->find($quote->id));

        // Al volver a tener la función, todo como estaba.
        $this->onPlan('profesional');
        Livewire::test(ListQuotes::class)->assertNoRedirect();
        $this->assertSame('application/pdf', $this->get(route('quotes.pdf', $quote))->assertOk()->headers->get('Content-Type'));
    }

    public function test_report_videos_follow_the_plan_everywhere_and_are_kept(): void
    {
        $this->onPlan('profesional');
        $report = Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $path = 'reports/'.$this->a['company']->id.'/videos/'.Str::random(20).'.mp4';
        Storage::disk('local')->put($path, 'video');
        $video = new ReportVideo;
        $video->forceFill(['company_id' => $this->a['company']->id, 'report_id' => $report->id, 'path' => $path, 'mime' => 'video/mp4', 'size' => 5, 'status' => ReportVideo::READY])->save();

        $this->flushSession();
        $this->actingAs($this->a['technician']);
        $this->get(route('reports.video', $report))->assertOk();
        $this->get(route('reports.video.status', $report))->assertOk();

        // Sin video en el plan: no se ve, no se consulta, no se borra ni se procesa; queda guardado.
        $this->onPlan('inicial');
        $this->flushSession();
        $this->actingAs($this->a['technician']);
        $this->get(route('reports.video', $report))->assertNotFound();
        $this->get(route('reports.video.status', $report))->assertNotFound();
        $this->delete(route('reports.video.destroy', $report))->assertForbidden();
        $this->get(route('reports.show', ['company' => $this->a['company']->slug, 'report' => $report]))->assertDontSee('/video');
        $pending = new ReportVideo;
        $pending->forceFill(['company_id' => $this->a['company']->id, 'report_id' => Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id])->id,
            'path' => 'reports/'.$this->a['company']->id.'/videos/'.Str::random(20).'.mp4', 'mime' => 'video/mp4', 'size' => 5, 'status' => ReportVideo::PENDING])->save();
        $this->assertSame(0, app(ReportVideoService::class)->processPending(5));
        $this->assertSame(ReportVideo::PENDING, $pending->fresh()->status);
        $this->assertSame(2, ReportVideo::withoutGlobalScopes()->count());
        Storage::disk('local')->assertExists($path);

        // Al volver a tenerlo, se ve y se procesa lo pendiente.
        $this->onPlan('profesional');
        $this->flushSession();
        $this->actingAs($this->a['technician']);
        $this->get(route('reports.video', $report))->assertOk();
        $this->assertSame(1, app(ReportVideoService::class)->processPending(5));
    }

    public function test_the_portal_respects_videos_and_keeps_quotes_already_shared(): void
    {
        $this->onPlan('profesional');
        $client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $report = Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $report->shareWithClient(true);
        $path = 'reports/'.$this->a['company']->id.'/videos/'.Str::random(20).'.mp4';
        Storage::disk('local')->put($path, 'video');
        (new ReportVideo)->forceFill(['company_id' => $this->a['company']->id, 'report_id' => $report->id, 'path' => $path, 'mime' => 'video/mp4', 'size' => 5, 'status' => ReportVideo::READY])->save();
        $quote = Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'client_id' => $client->id, 'status' => Quote::SENT]);
        $quote->shareWithClient(true);
        auth()->logout();

        $portal = User::factory()->create(['email' => 'consorcio@demo.test']);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);
        $asPortal = function () use ($portal) {
            $this->flushSession();
            $this->actingAs($portal->fresh());
        };

        $asPortal();
        $this->get(route('portal.report-video', $report))->assertOk();
        $this->get(route('portal.quote', $quote))->assertOk();

        // El SuperAdmin quita video y presupuestos de Profesional.
        $this->setFeature('profesional', PlanFeature::ReportVideos, false);
        $this->setFeature('profesional', PlanFeature::Quotes, false);
        $this->a['company']->forgetPlan();
        $asPortal();
        $this->get(route('portal.report-video', $report))->assertNotFound();
        $this->get(route('portal.report', $report))->assertOk()->assertDontSee(route('portal.report-video', $report));
        // Lo que ya se compartió con el cliente sigue visible (como un enlace ya enviado).
        $this->get(route('portal.quote', $quote))->assertOk();

        $this->setFeature('profesional', PlanFeature::ReportVideos, true);
        $this->setFeature('profesional', PlanFeature::Quotes, true);
        $asPortal();
        $this->get(route('portal.report-video', $report))->assertOk();
    }
}
