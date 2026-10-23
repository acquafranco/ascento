<?php

namespace Tests\Feature\Stage11;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\ReportPhoto;
use App\Models\StockItem;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use Database\Seeders\DemoValidationSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11: matriz de aislamiento entre empresas. Con las dos empresas de
 * la demo (Norte = la atacada, con datos de todos los módulos; Sur = la que
 * ataca), cada ruta y cada página del panel que recibe un id se prueba con
 * ids de Norte desde las cuentas de Sur (dueño, técnico y portal), y se
 * verifica con una huella de toda la base que nada de Norte cambió.
 *
 * Recorre las rutas y los recursos registrados: una ruta o un recurso nuevo
 * queda cubierto sin tocar esta prueba.
 */
class CrossTenantMatrixTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Company $north;

    private Company $south;

    /** @var array<class-string<Model>, list<int>> ids de Norte por modelo */
    protected array $northIds = [];

    /** Tablas que cambian solas (sesiones, caché, colas, registros técnicos). */
    private const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'webhook_events', 'activity_logs', 'migrations'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(DemoValidationSeeder::class);

        $this->north = Company::where('slug', 'demo-norte')->firstOrFail();
        $this->south = Company::where('slug', 'demo-sur')->firstOrFail();
        // Las dos con todas las funciones: ningún 404 por plan tapa un acceso cruzado.
        $this->giveCompanyPlan($this->north, 'empresa');
        $this->giveCompanyPlan($this->south, 'empresa');

        foreach ($this->tenantModels() as $model) {
            $ids = $model::withoutGlobalScopes()->where('company_id', $this->north->id)->orderBy('id')->limit(3)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                $this->northIds[$model] = $ids;
            }
        }
        // Usuarios de Norte (User no tiene scope de empresa).
        $this->northIds[User::class] = User::where('company_id', $this->north->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<class-string<Model>> */
    private function tenantModels(): array
    {
        return collect(glob(app_path('Models/*.php')))
            ->map(fn ($file) => 'App\\Models\\'.basename($file, '.php'))
            ->filter(fn ($class) => class_exists($class) && in_array(BelongsToCompany::class, class_uses_recursive($class), true))
            ->values()->all();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Huella de toda la base (menos lo volátil): cualquier cambio la altera. */
    protected function fingerprint(): array
    {
        // Solo la base de esta conexión: en MySQL, sin esquema, se listan las tablas de TODAS las bases del servidor.
        $schema = DB::connection()->getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;
        $tables = collect(Schema::getTableListing($schema))->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->reject(fn ($t) => in_array($t, self::VOLATILE, true))->sort()->values();

        return $tables->mapWithKeys(function ($table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->map(fn ($r) => json_encode($r))->sort()->values();

            return [$table => md5($rows->implode("\n"))];
        })->all();
    }

    private function assertNothingChanged(array $before, string $context): void
    {
        $after = $this->fingerprint();
        $changed = array_keys(array_diff_assoc($after, $before));
        $this->assertSame([], $changed, "{$context}: cambiaron las tablas ".implode(', ', $changed));
    }

    /** Parámetros de modelo de una ruta: nombre => clase. */
    private function modelParameters(Route $route): array
    {
        $params = [];
        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $class = $parameter->getType()?->getName();
            if ($class && $class !== Company::class) {
                $params[$parameter->getName()] = $class;
            }
        }

        return $params;
    }

    /** Rutas web (fuera del panel) con algún id de modelo, con los ids de Norte puestos. */
    protected function attacks(): array
    {
        $attacks = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'admin') || str_starts_with($uri, 'livewire') || str_starts_with($uri, 'filament') || str_contains($uri, '{token}')) {
                continue;
            }
            $models = $this->modelParameters($route);
            // Parámetros enteros sin tipo de modelo ({visit}, {workOrder} como int): se prueban con ids de Norte del modelo de nombre igual.
            foreach ($route->parameterNames() as $name) {
                if (! isset($models[$name]) && ($class = $this->guessModel($name))) {
                    $models[$name] = $class;
                }
            }
            if ($models === []) {
                continue;
            }
            foreach ($models as $name => $class) {
                foreach ($this->northIds[$class] ?? [] as $id) {
                    $params = ['company' => $this->south->slug];
                    foreach ($route->parameterNames() as $other) {
                        $params[$other] = match (true) {
                            $other === $name => $id,
                            isset($models[$other]) => $this->northIds[$models[$other]][0] ?? 1,
                            $other === 'type' => 'maintenance',
                            $other === 'format' => 'png',
                            $other === 'date' => now()->toDateString(),
                            default => $params[$other] ?? 1,
                        };
                    }
                    $attacks[] = [$route, $params];
                }
            }
        }

        return $attacks;
    }

    private function guessModel(string $parameter): ?string
    {
        $class = 'App\\Models\\'.ucfirst($parameter);
        if (class_exists($class) && isset($this->northIds[$class])) {
            return $class;
        }

        return match ($parameter) {
            'visit' => BuildingVisit::class,
            'photo' => ReportPhoto::class,
            default => null,
        };
    }

    /** Cuerpo de un pedido de escritura con ids de Norte en todos los campos habituales. */
    private function hostileBody(): array
    {
        $first = fn (string $class) => $this->northIds[$class][0] ?? 1;

        return [
            'company_id' => $this->north->id,
            'building_id' => $first(Building::class),
            'client_id' => $first(Client::class),
            'elevator_id' => $first(Elevator::class),
            'work_order_id' => $first(WorkOrder::class),
            'building_visit_id' => $first(BuildingVisit::class),
            'quote_id' => $first(Quote::class),
            'stock_item_id' => $first(StockItem::class),
            'user_id' => $first(User::class),
            'participants' => $this->northIds[User::class],
            'visit_photo_id' => $first(VisitPhoto::class),
            'assignment_type' => 'maintenance',
            'section' => 'general',
            'performed' => 1,
        ];
    }

    public function test_every_web_route_rejects_ids_of_another_company_for_every_role(): void
    {
        $attacks = $this->attacks();
        $this->assertGreaterThan(40, count($attacks), 'La matriz tiene que recorrer las rutas con ids.');
        $before = $this->fingerprint();

        foreach (['admin@demo-sur.test', 'nora@demo-sur.test', 'portal@consorcio-sur.test'] as $email) {
            $actor = $this->user($email);
            foreach ($attacks as [$route, $params]) {
                $this->flushSession();
                $this->actingAs($actor->fresh());
                $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
                $url = route($route->getName(), $params, false);
                $response = $this->call($method, $url, $method === 'GET' ? [] : $this->hostileBody());
                $status = $response->getStatusCode();

                $this->assertNotSame(500, $status, "{$email} {$method} {$url}: error del servidor");
                $this->assertFalse($status >= 200 && $status < 300, "{$email} {$method} {$url} respondió {$status} con un id de otra empresa");
            }
        }

        $this->assertNothingChanged($before, 'Rutas web');
    }

    public function test_every_panel_page_with_a_record_rejects_records_of_another_company(): void
    {
        $owner = $this->user('admin@demo-sur.test');
        $checked = 0;

        foreach (Filament::getPanel('ascensores_app')->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            $model = $resource::getModel();
            $ids = $this->northIds[$model] ?? [];
            foreach ($resource::getPages() as $name => $page) {
                $routeName = $page->getPage()::getRouteName(Filament::getPanel('ascensores_app'));
                if (! in_array('record', RouteFacade::getRoutes()->getByName($routeName)?->parameterNames() ?? [], true)) {
                    continue;
                }
                foreach ($ids as $id) {
                    $this->flushSession();
                    $this->actingInPanel($owner->fresh());
                    $status = $this->get($resource::getUrl($name, ['record' => $id]))->getStatusCode();
                    $this->assertContains($status, [403, 404], "{$resource}::{$name} con el registro {$id} de otra empresa respondió {$status}");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function test_table_actions_cannot_be_run_on_records_of_another_company(): void
    {
        $owner = $this->user('admin@demo-sur.test');
        $before = $this->fingerprint();
        $tried = 0;

        foreach (Filament::getPanel('ascensores_app')->getResources() as $resource) {
            $ids = $this->northIds[$resource::getModel()] ?? [];
            $pages = $resource::getPages();
            if ($ids === [] || ! isset($pages['index'])) {
                continue;
            }
            $this->flushSession();
            $this->actingInPanel($owner->fresh());
            try {
                $page = Livewire::test($pages['index']->getPage());
            } catch (HttpException) {
                continue; // la página no es para el dueño (p. ej. solo SuperAdmin)
            }
            $table = $page->instance()->getTable();
            $names = collect($table->getFlatActions())->map->getName()->unique()->values();
            $bulk = collect($table->getFlatBulkActions())->map->getName()->unique()->values();

            foreach ($ids as $id) {
                foreach ($names as $name) {
                    $tried++;
                    try {
                        $page->call('mountAction', $name, [], ['table' => true, 'recordKey' => (string) $id]);
                        $page->call('callMountedAction');
                    } catch (HttpException) {
                        // 403/404: bloqueado, como corresponde.
                    }
                }
            }
            foreach ($bulk as $name) {
                $tried++;
                try {
                    $page->set('selectedTableRecords', array_map('strval', $ids));
                    $page->call('mountAction', $name, [], ['table' => true, 'bulk' => true]);
                    $page->call('callMountedAction');
                } catch (HttpException) {
                }
            }
        }

        $this->assertGreaterThan(20, $tried);
        $this->assertNothingChanged($before, 'Acciones de tabla');
    }
}
