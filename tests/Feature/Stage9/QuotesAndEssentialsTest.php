<?php

namespace Tests\Feature\Stage9;

use App\Enums\PlanFeature;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\IndicatorsPage;
use App\Filament\Resources\Elevators\Pages\ListElevators;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Models\Client;
use App\Models\Quote;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\ClientDossier;
use App\Services\Staff\StaffManager;
use App\Services\Tasks\TaskService;
use App\Support\Plans\PlanUpsell;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cierre de la revisión de la Etapa 9: sin presupuestos en el plan, las
 * tareas no muestran ni aceptan presupuestos (las existentes no se tocan) y
 * la ficha del cliente no los muestra. Las funciones esenciales están en los
 * tres planes aunque una fila histórica del plan no tenga sus claves.
 */
class QuotesAndEssentialsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Client $client;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->client = Client::withoutGlobalScopes()->find($this->a['building']->client_id);
        $this->quote = Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'client_id' => $this->client->id, 'status' => Quote::SENT]);
    }

    private function onPlan(string $slug, ?User $user = null): void
    {
        $this->giveCompanyPlan($this->a['company'], $slug);
        $this->a['company']->refresh();
        $this->flushSession();
        $this->actingAs(($user ?? $this->a['admin'])->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function invalid(\Closure $call, string $field): void
    {
        try {
            $call();
            $this->fail("Debía rechazar {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_inicial_cannot_see_or_link_quotes_from_tasks(): void
    {
        $this->onPlan('inicial');

        // Interfaz: sin selector de presupuestos.
        Livewire::test(CreateTask::class)->assertFormFieldIsHidden('quote_id');

        // Servidor: un pedido manipulado no asocia el presupuesto.
        $this->invalid(fn () => app(TaskService::class)->create($this->a['admin'], ['title' => 'Llamar', 'priority' => 'low', 'quote_id' => $this->quote->id]), 'quote_id');
        $this->assertSame(0, Task::withoutGlobalScopes()->whereNotNull('quote_id')->count());

        // Desde el formulario, aunque se fuerce el valor, la tarea se crea sin presupuesto.
        Livewire::test(CreateTask::class)->fillForm(['title' => 'Desde el panel', 'priority' => 'low', 'quote_id' => $this->quote->id])->call('create');
        $this->assertNull(Task::withoutGlobalScopes()->where('title', 'Desde el panel')->value('quote_id'));
        $this->assertSame(1, Task::withoutGlobalScopes()->where('title', 'Desde el panel')->count());
    }

    public function test_existing_task_quote_links_are_kept_untouched_on_inicial(): void
    {
        $this->onPlan('profesional');
        $task = app(TaskService::class)->create($this->a['admin'], ['title' => 'Seguir presupuesto', 'priority' => 'low', 'quote_id' => $this->quote->id]);
        $other = Quote::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'status' => Quote::SENT]);

        $this->onPlan('inicial');
        // Editar otros datos (también desde el panel) conserva el vínculo.
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['title' => 'Seguir presupuesto (editada)']);
        Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])->assertFormFieldIsHidden('quote_id')
            ->fillForm(['title' => 'Editada en el panel'])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$this->quote->id, 'Editada en el panel'], [(int) $task->fresh()->quote_id, $task->fresh()->title]);
        // Mandar el mismo valor no es un cambio.
        app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['quote_id' => $this->quote->id]);
        // Cambiarlo o quitarlo con un pedido manipulado: rechazado.
        $this->invalid(fn () => app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['quote_id' => $other->id]), 'quote_id');
        $this->invalid(fn () => app(TaskService::class)->update($this->a['admin'], $task->fresh(), ['quote_id' => null]), 'quote_id');
        $this->assertSame($this->quote->id, (int) $task->fresh()->quote_id);
    }

    public function test_profesional_and_empresa_keep_quotes_in_tasks_and_the_client_file(): void
    {
        foreach (['profesional', 'empresa'] as $slug) {
            $this->onPlan($slug);
            Livewire::test(CreateTask::class)->assertFormFieldIsVisible('quote_id');
            $task = app(TaskService::class)->create($this->a['admin'], ['title' => "Con presupuesto {$slug}", 'priority' => 'low', 'quote_id' => $this->quote->id]);
            $this->assertSame($this->quote->id, (int) $task->quote_id);
            $this->assertNotNull(app(ClientDossier::class)->for($this->client, $this->a['admin'])['quotes']);
        }
    }

    public function test_the_client_file_hides_quotes_without_the_plan_or_the_permission(): void
    {
        $this->onPlan('inicial');
        $dossier = app(ClientDossier::class)->for($this->client, $this->a['admin']);
        $this->assertNull($dossier['quotes']);
        $this->assertNull($dossier['summary']['quotes'] ?? null);

        // Con el plan pero sin el permiso del usuario, tampoco.
        $this->onPlan('profesional');
        User::where('company_id', $this->a['company']->id)->where('role', User::ROLE_STAFF)->get()->each->delete();
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Ana Oficina', 'email' => 'ana@demo.test', 'password' => 'clave-segura-123'], ['clients' => 'view']);
        $this->onPlan('profesional', $staff);
        $this->assertNull(app(ClientDossier::class)->for($this->client, $staff)['quotes']);
    }

    public function test_tasks_never_link_quotes_of_another_company(): void
    {
        $b = $this->makeTenant();
        $foreign = Quote::factory()->create(['company_id' => $b['company']->id, 'building_id' => $b['building']->id, 'status' => Quote::SENT]);
        $this->onPlan('empresa');
        $this->invalid(fn () => app(TaskService::class)->create($this->a['admin'], ['title' => 'Ajena', 'priority' => 'low', 'quote_id' => $foreign->id]), 'quote_id');
    }

    public function test_essentials_are_on_every_plan_even_with_incomplete_historical_rows(): void
    {
        // Filas históricas sin claves: ni siquiera las esenciales guardadas.
        DB::table('subscription_plans')->update(['feature_keys' => json_encode([])]);

        foreach (['inicial', 'profesional', 'empresa'] as $slug) {
            $this->onPlan($slug);
            $plan = SubscriptionPlan::query()->where('slug', $slug)->sole();
            foreach (PlanFeature::structural() as $essential) {
                $this->assertTrue($plan->allows($essential), "{$slug}: {$essential->value}");
                $this->assertTrue($this->a['company']->plan()->allows($essential), "{$slug} (empresa): {$essential->value}");
            }
            // Las configurables siguen sujetas al plan.
            $this->assertFalse($plan->allows(PlanFeature::Map));
            $this->assertFalse($plan->allows(PlanFeature::Quotes));

            Livewire::test(ListElevators::class)->assertNoRedirect();
            Livewire::test(IndicatorsPage::class)->assertNoRedirect();
            Livewire::test(BuildingsMap::class)->assertRedirect(PlanUpsell::url(feature: PlanFeature::Map));
        }
    }
}
