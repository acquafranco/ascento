<?php

namespace Tests\Feature\Stage9;

use App\Enums\PlanFeature;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Filament\Pages\PlanFeatures;
use App\Filament\Pages\PublicQrPage;
use App\Filament\RelationManagers\InteractionsRelationManager;
use App\Filament\Resources\ClientInteractions\Pages\ListClientInteractions;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\RepairComponents\Pages\ListRepairComponents;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\RepairComponent;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\ClientDossier;
use App\Services\Crm\ClientInteractionService;
use App\Services\Insights\DashboardSummary;
use App\Services\Plans\PlanFeatureManager;
use App\Services\PublicQr\PublicQrService;
use App\Services\Repairs\RepairService;
use App\Services\Tasks\TaskService;
use App\Support\Plans\PlanUpsell;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 9.2: las funciones de las Etapas 6 a 8 se declaran en los planes.
 * Tareas, QR, paracaídas y reparaciones en todos; historial de contactos
 * desde Profesional. Control en el servidor, sin borrar datos al bajar de
 * plan, y el SuperAdmin decide qué funciones incluye cada plan (no precios
 * ni límites).
 */
class PlanFeaturesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
    }

    private function inPanel(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function onPlan(string $slug): void
    {
        $this->giveCompanyPlan($this->a['company'], $slug);
        $this->a['company']->refresh();
        $this->inPanel($this->a['admin']->fresh());
    }

    private function unavailable(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Debía rechazarlo por el plan.');
        } catch (PlanFeatureUnavailableException $e) {
            $this->assertStringContainsString('', $e->getMessage());
        }
    }

    public function test_the_matrix_adds_the_new_features_without_touching_prices_or_limits(): void
    {
        $plans = SubscriptionPlan::query()->get()->keyBy('slug');
        foreach (['inicial', 'profesional', 'empresa'] as $slug) {
            foreach ([PlanFeature::Tasks, PlanFeature::PublicQr, PlanFeature::ParachuteTests, PlanFeature::Repairs] as $feature) {
                $this->assertTrue($plans[$slug]->allows($feature), "{$slug} debe incluir {$feature->value}");
            }
        }
        $this->assertFalse($plans['inicial']->allows(PlanFeature::ClientContacts));
        $this->assertTrue($plans['profesional']->allows(PlanFeature::ClientContacts));
        $this->assertTrue($plans['empresa']->allows(PlanFeature::ClientContacts));

        // Precios y límites, sin cambios.
        $this->assertSame(['69000.00', '119000.00', '169000.00'], [$plans['inicial']->price, $plans['profesional']->price, $plans['empresa']->price]);
        $this->assertSame([20, 50, 3, 15, 0], [$plans['inicial']->max_buildings, $plans['inicial']->max_clients, $plans['inicial']->max_technicians, $plans['inicial']->max_reports_per_month, $plans['inicial']->max_admin_users]);
        $this->assertSame([70, 150, 10, null, 1], [$plans['profesional']->max_buildings, $plans['profesional']->max_clients, $plans['profesional']->max_technicians, $plans['profesional']->max_reports_per_month, $plans['profesional']->max_admin_users]);
        $this->assertSame([300, 420, 25, null, 5], [$plans['empresa']->max_buildings, $plans['empresa']->max_clients, $plans['empresa']->max_technicians, $plans['empresa']->max_reports_per_month, $plans['empresa']->max_admin_users]);

        // La prueba gratis sigue siendo Empresa completo.
        $this->assertTrue($this->a['company']->plan()->allows(PlanFeature::ClientContacts));
        $this->assertContains('Historial de contactos con clientes', $plans['profesional']->highlights());
        $this->assertNotContains('Historial de contactos con clientes', $plans['inicial']->highlights());
    }

    public function test_contacts_are_closed_on_inicial_and_their_data_is_kept(): void
    {
        $this->onPlan('empresa');
        $interaction = app(ClientInteractionService::class)->record($this->a['admin'], ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()->subDays(70)]);

        $this->onPlan('inicial');
        // Pantallas: «Ver planes» en vez de la sección.
        Livewire::test(ListClientInteractions::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::ClientContacts));
        $this->assertFalse(InteractionsRelationManager::canViewForRecord($this->client, EditClient::class));
        // Servidor: no se registra ni se corrige.
        $this->unavailable(fn () => app(ClientInteractionService::class)->record($this->a['admin'], ['client_id' => $this->client->id, 'type' => 'call', 'result' => 'resolved', 'occurred_at' => now()]));
        $this->unavailable(fn () => app(ClientInteractionService::class)->update($this->a['admin'], $interaction, ['client_id' => $this->client->id, 'type' => 'email', 'result' => 'resolved', 'occurred_at' => now()]));
        // Inicio y ficha del cliente: sin el bloque de contactos.
        $this->assertNull(app(DashboardSummary::class)->followUp($this->a['company'], $this->a['admin'])['contacts']);
        $this->assertNull(app(ClientDossier::class)->for($this->client, $this->a['admin'])['contacts'] ?? null);
        // Nada se borra.
        $this->assertSame(1, ClientInteraction::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        // Al subir de plan, vuelve todo.
        $this->onPlan('profesional');
        $this->get('/admin/contactos')->assertOk();
        Livewire::test(ListClientInteractions::class)->assertNoRedirect();
        $this->assertTrue(InteractionsRelationManager::canViewForRecord($this->client, EditClient::class));
        $this->assertNotNull(app(DashboardSummary::class)->followUp($this->a['company'], $this->a['admin'])['contacts']);
        $this->assertNotNull(app(ClientDossier::class)->for($this->client, $this->a['admin'])['contacts'] ?? null);
    }

    public function test_the_other_features_stay_on_every_plan(): void
    {
        $this->onPlan('inicial');
        $this->get('/admin/tareas')->assertOk();
        $this->get('/admin/qr-publicos')->assertOk();
        $this->get('/admin/paracaidas')->assertOk();
        $this->get('/admin/reparaciones')->assertOk();
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'Llamar', 'priority' => 'low']);
        $this->assertNotNull($task->id);
        $repair = app(RepairService::class)->create($this->a['admin'], ['building_id' => $this->a['building']->id, 'component_type' => 'motor', 'failure' => 'x']);
        $this->assertNotNull($repair->id);
        $this->assertNotNull(app(PublicQrService::class)->generate($this->a['admin'], $this->a['building'])->token);
    }

    public function test_the_super_admin_switches_features_per_plan_and_nothing_is_deleted(): void
    {
        $this->onPlan('inicial');
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'Llamar', 'priority' => 'low', 'remind_at' => '2026-10-14 08:00']);
        $repair = app(RepairService::class)->create($this->a['admin'], ['building_id' => $this->a['building']->id, 'component_type' => 'motor', 'failure' => 'x']);
        $file = app(RepairService::class)->attach($this->a['admin'], $repair, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"));
        $qr = app(PublicQrService::class)->generate($this->a['admin'], $this->a['building']);
        $token = $qr->token;
        auth()->logout();

        // El SuperAdmin quita tareas, QR y reparaciones de Inicial.
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($super);
        Log::spy();
        $inicial = SubscriptionPlan::findBySlug('inicial');
        $all = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::toggleable());
        $result = app(PlanFeatureManager::class)->update($super, $inicial, array_values(array_diff($all, ['tasks', 'public_qr', 'repairs', 'client_contacts', 'quotes', 'digital_delivery_notes', 'attention_advanced', 'company_indicators', 'elevator_history_advanced', 'failure_analysis', 'client_portal', 'report_videos', 'advanced_indicators', 'advanced_alerts', 'work_photos', 'emergency_requests'])));
        $this->assertEqualsCanonicalizing(['tasks', 'public_qr', 'repairs'], $result['removed']);
        $this->assertSame([], $result['added']);
        Log::shouldHaveReceived('notice')->once();

        $this->onPlan('inicial');
        // Pantallas y servidor cerrados; el QR impreso no muestra nada; los archivos, 404.
        Livewire::test(ListTasks::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::Tasks));
        Livewire::test(ListRepairComponents::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::Repairs));
        Livewire::test(PublicQrPage::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::PublicQr));
        $this->unavailable(fn () => app(TaskService::class)->create($this->a['admin'], ['title' => 'Otra', 'priority' => 'low']));
        $this->unavailable(fn () => app(RepairService::class)->changeStatus($this->a['admin'], $repair, RepairComponent::CANCELLED));
        $this->unavailable(fn () => app(PublicQrService::class)->deactivate($this->a['admin'], $qr->fresh()));
        $this->get($file->url())->assertNotFound();
        $this->assertNull(app(DashboardSummary::class)->followUp($this->a['company'], $this->a['admin'])['tasks']);
        auth()->logout();
        $this->flushSession();
        $this->get('/qr/'.$token)->assertNotFound();
        // El recordatorio de la tarea no sale mientras no tenga la función.
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertNull($task->fresh()->reminded_at);

        // Nada se borró, y al volver a incluirlas, todo vuelve (el mismo QR impreso).
        $this->assertSame([1, 1, 1], [Task::withoutGlobalScopes()->count(), RepairComponent::withoutGlobalScopes()->count(), $qr->fresh()->is_active ? 1 : 0]);
        $this->inPanel($super);
        app(PlanFeatureManager::class)->update($super, SubscriptionPlan::query()->where('slug', 'inicial')->sole(), [...array_values(array_diff($all, ['client_contacts', 'quotes', 'digital_delivery_notes', 'attention_advanced', 'company_indicators', 'elevator_history_advanced', 'failure_analysis', 'client_portal', 'report_videos', 'advanced_indicators', 'advanced_alerts', 'work_photos']))]);
        auth()->logout();
        $this->flushSession();
        $this->get('/qr/'.$token)->assertOk();
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertNotNull($task->fresh()->reminded_at);
        $this->onPlan('inicial');
        Livewire::test(ListRepairComponents::class)->assertNoRedirect();
        $this->get($file->url())->assertOk();
    }

    public function test_only_the_super_admin_manages_features_and_never_prices_limits_or_the_base(): void
    {
        $plan = SubscriptionPlan::query()->where('slug', 'profesional')->sole();
        $before = $plan->only(['price', 'max_buildings', 'max_clients', 'max_technicians', 'max_reports_per_month', 'max_admin_users']);

        // El dueño ni entra ni guarda.
        $this->inPanel($this->a['admin']);
        $this->get('/admin/planes')->assertForbidden();
        try {
            app(PlanFeatureManager::class)->update($this->a['admin'], $plan, []);
            $this->fail('Solo el SuperAdmin.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // El SuperAdmin: la base no se puede quitar y las claves desconocidas se ignoran.
        auth()->logout();
        $super = User::factory()->superAdmin()->create();
        $this->inPanel($super);
        $this->get('/admin/planes')->assertOk()->assertSee('Historial de contactos con clientes')->assertSee('solo lectura');
        app(PlanFeatureManager::class)->update($super, $plan, ['inventado', 'buildings', 'quotes']);
        $plan->refresh();
        foreach (PlanFeature::structural() as $base) {
            $this->assertTrue($plan->allows($base), "{$base->value} es la base");
        }
        $this->assertTrue($plan->allows(PlanFeature::Quotes));
        $this->assertFalse($plan->allows(PlanFeature::ClientPortal));
        $this->assertNotContains('inventado', $plan->feature_keys);
        $this->assertSame($before, $plan->only(array_keys($before)));

        // Desde la pantalla, con confirmación.
        Livewire::test(PlanFeatures::class)
            ->set('features.profesional.client_portal', true)
            ->callAction('save');
        $this->assertTrue($plan->fresh()->allows(PlanFeature::ClientPortal));
        $this->assertSame($before, $plan->fresh()->only(array_keys($before)));
    }
}
