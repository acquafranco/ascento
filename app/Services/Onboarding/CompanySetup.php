<?php

namespace App\Services\Onboarding;

use App\Filament\Pages\CompanySetupWizard;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Buildings\Schemas\BuildingForm;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderAssignmentNotifier;
use App\Support\Access\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración inicial de una empresa nueva, en un asistente propio
 * (CompanySetupWizard): datos y marca de la empresa, primer técnico, primer
 * cliente, primer edificio con sus ascensores y primera orden de trabajo
 * asignada a ese técnico.
 *
 * - El estado es de la EMPRESA (companies.setup_*), no de un usuario.
 * - El avance sale de registros REALES: el asistente los crea con los mismos
 *   campos, reglas y efectos que los formularios del panel (UserResource,
 *   ClientForm, BuildingForm, WorkOrderForm), y si alguno ya existía (cargado
 *   desde el panel) el paso cuenta como hecho: nada se duplica ni es ficticio.
 *   Solo «empresa», «marca» y la bienvenida se marcan al guardarse.
 * - Límites del plan: los mismos (el modelo los vuelve a verificar al crear).
 * - Solo la usa el dueño de la empresa (canUseOnboarding).
 * - Se puede abandonar y retomar: el progreso queda en la base; «Seguir
 *   después» solo deja de abrirla sola durante la sesión.
 */
final class CompanySetup
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const PAUSED_SESSION_KEY = 'company_setup.paused';

    /** Pantallas del asistente, en orden. «company» y «brand» forman el paso 1. */
    public const SCREENS = ['welcome', 'company', 'brand', 'technician', 'client', 'building', 'work_order', 'done'];

    /** Pasos que se muestran en la barra de progreso (pantalla => número de paso). */
    public const STEP_OF = ['company' => 1, 'brand' => 1, 'technician' => 2, 'client' => 3, 'building' => 4, 'work_order' => 5];

    public const STEP_TITLES = [1 => 'Tu empresa', 2 => 'Tu técnico', 3 => 'Tu cliente', 4 => 'Tu edificio', 5 => 'Tu primera orden'];

    public const ACKS = ['welcome', 'company', 'brand'];

    public static function appliesTo(?User $user): bool
    {
        return $user instanceof User && $user->canUseOnboarding() && $user->company !== null && $user->company->setup_status !== null;
    }

    /** ¿Se abre sola? (empresa nueva con la configuración pendiente y sin pausar en esta sesión). */
    public static function isActiveFor(?User $user): bool
    {
        return self::appliesTo($user)
            && $user->company->setup_status === self::PENDING
            && $user->company->hasActiveAccess()
            && ! session(self::PAUSED_SESSION_KEY);
    }

    /**
     * Lo hecho, con los registros reales de la empresa.
     *
     * @return array{acks: list<string>, technician: ?User, client: ?Client, building: ?Building, order: ?WorkOrder, done: array<string, bool>, next: string, steps_done: int}
     */
    public function progress(Company $company): array
    {
        $acks = array_values(array_filter((array) ($company->setup_acks ?? []), 'is_string'));
        $id = $company->id;

        // El primer técnico, el primer cliente, su primer edificio con equipos y
        // la primera orden asignada a ese técnico (pocas consultas, solo
        // mientras la configuración no terminó).
        $technician = User::where('company_id', $id)->where('role', 'technician')->whereNull('deleted_at')->orderBy('id')->first();
        $client = Client::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')->orderBy('id')->first();
        $withEquipment = fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('elevators')->whereColumn('elevators.building_id', 'buildings.id'));
        $building = $client
            ? Building::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')->where('client_id', $client->id)->tap($withEquipment)->orderBy('id')->first()
            : null;
        $order = $technician
            ? WorkOrder::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')
                ->whereHas('users', fn ($q) => $q->where('users.id', $technician->id))->orderBy('id')->first()
            : null;

        $done = [
            'welcome' => in_array('welcome', $acks, true),
            'company' => in_array('company', $acks, true),
            'brand' => in_array('brand', $acks, true),
            'technician' => $technician !== null,
            'client' => $client !== null,
            'building' => $building !== null,
            'work_order' => $order !== null,
        ];

        $next = 'done';
        foreach (self::SCREENS as $screen) {
            if ($screen !== 'done' && ! $done[$screen]) {
                $next = $screen;
                break;
            }
        }

        $stepsDone = collect(self::STEP_TITLES)->keys()
            ->filter(fn (int $step) => collect(self::STEP_OF)->filter(fn ($s) => $s === $step)->keys()->every(fn ($screen) => $done[$screen]))
            ->count();

        return compact('acks', 'technician', 'client', 'building', 'order', 'done', 'next') + ['steps_done' => $stepsDone];
    }

    /** Marca una pantalla que no depende de un registro. */
    public function acknowledge(Company $company, string $key): void
    {
        abort_unless(in_array($key, self::ACKS, true), 404);

        DB::transaction(function () use ($company, $key) {
            $fresh = Company::whereKey($company->id)->lockForUpdate()->first();
            $acks = (array) ($fresh->setup_acks ?? []);
            if (! in_array($key, $acks, true)) {
                $fresh->forceFill(['setup_acks' => [...$acks, $key]])->save();
            }
        });
        $company->refresh();
    }

    /* ------------------------------------------------------------------
     | Guardado de cada paso. Los datos ya vienen validados por los mismos
     | campos del panel (getState()); acá se repiten los efectos del alta.
     * ------------------------------------------------------------------ */

    public function saveCompany(Company $company, array $data, string $screen): void
    {
        // Solo los campos de esa pantalla (getState() no trae otros).
        $company->update(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'logo', 'primary_color'])));
        $this->acknowledge($company, $screen);
    }

    /** Primer técnico (como CreateUser): la empresa la pone el modelo, y se le manda la confirmación del correo. */
    public function createTechnician(Company $company, array $data): User
    {
        if ($existing = $this->progress($company)['technician']) {
            return $existing; // un reenvío no crea otro
        }

        $user = User::create(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'password'])) + ['role' => 'technician']);
        $user->sendVerificationSafely();

        return $user;
    }

    public function createClient(Company $company, array $data): Client
    {
        if ($existing = $this->progress($company)['client']) {
            return $existing;
        }

        return Client::create(array_intersect_key($data, array_flip(['name', 'type', 'contact_person', 'phone', 'email'])) + ['is_active' => true]);
    }

    /** Edificio del cliente del paso anterior, con sus equipos (como CreateBuilding). */
    public function createBuilding(Company $company, array $data, ?string $addressSearch): Building
    {
        $progress = $this->progress($company);
        if ($progress['building']) {
            return $progress['building'];
        }
        if (! $progress['client']) {
            throw ValidationException::withMessages(['client_id' => 'Primero cargá el cliente.']);
        }

        return DB::transaction(function () use ($data, $progress, $addressSearch) {
            $building = Building::create(array_intersect_key($data, array_flip(['name', 'address', 'locality', 'municipality', 'province', 'neighborhood']))
                + ['client_id' => $progress['client']->id, 'is_active' => true]);
            BuildingForm::afterCreate($building, (array) ($data['equipment'] ?? []), $addressSearch);

            return $building;
        });
    }

    /** Primera orden, en ese edificio y asignada a ese técnico (como CreateWorkOrder: el técnico recibe el aviso). */
    public function createWorkOrder(Company $company, array $data): WorkOrder
    {
        $progress = $this->progress($company);
        if ($progress['order']) {
            return $progress['order'];
        }
        if (! $progress['technician'] || ! $progress['building']) {
            throw ValidationException::withMessages(['unit' => 'Primero cargá el técnico y el edificio.']);
        }

        $building = $progress['building'];
        // Los mismos equipos que ofrece «Unidad» (WorkOrderForm::unitField).
        $units = collect();
        for ($i = 1; $i <= (int) $building->elevator_count; $i++) {
            $units->push("Ascensor {$i}");
        }
        for ($i = 1; $i <= (int) $building->freight_elevator_count; $i++) {
            $units->push("Montacargas {$i}");
        }
        if (! $units->contains($data['unit'] ?? null)) {
            throw ValidationException::withMessages(['unit' => 'Elegí un equipo de ese edificio.']);
        }

        $order = DB::transaction(function () use ($data, $building, $progress) {
            $order = WorkOrder::create([
                'building_id' => $building->id,
                'unit' => $data['unit'],
                'type' => $data['type'],
                'priority' => $data['priority'] ?? 'medium',
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);
            $order->users()->sync([$progress['technician']->id]);

            return $order;
        });

        app(WorkOrderAssignmentNotifier::class)->notifyNewAssignees($order);

        return $order;
    }

    /** Termina el recorrido (solo si todo está hecho). */
    public function complete(Company $company): bool
    {
        if ($this->progress($company)['next'] !== 'done') {
            return false;
        }
        $company->forceFill(['setup_status' => self::COMPLETED, 'setup_completed_at' => now()])->save();
        session()->forget(self::PAUSED_SESSION_KEY);

        return true;
    }

    public function skip(Company $company): void
    {
        $company->refresh(); // la empresa del usuario autenticado puede venir cargada de antes
        if ($company->setup_status === self::PENDING) {
            $company->forceFill(['setup_status' => self::SKIPPED])->save();
        }
    }

    /** Retomar (Ayuda, botón «?» o el aviso del inicio): vuelve a pendiente, donde quedó. */
    public function resume(Company $company): void
    {
        $company->refresh();
        if (in_array($company->setup_status, [self::SKIPPED, self::PENDING], true)) {
            $company->forceFill(['setup_status' => self::PENDING])->save();
        }
        session()->forget(self::PAUSED_SESSION_KEY);
    }

    /** Lo usa «Mi empresa» al guardar: los datos y la marca quedan hechos. */
    public static function companySaved(?User $user): void
    {
        if ($user && Access::isOwner($user) && $user->company?->setup_status === self::PENDING) {
            app(self::class)->acknowledge($user->company, 'company');
            app(self::class)->acknowledge($user->company, 'brand');
        }
    }

    public static function dashboardUrl(): string
    {
        return Dashboard::getUrl();
    }

    public static function wizardUrl(): string
    {
        return CompanySetupWizard::getUrl();
    }
}
