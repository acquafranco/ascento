<?php

namespace App\Services\Onboarding;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\Access;
use Illuminate\Support\Facades\DB;

/**
 * Configuración inicial guiada de una empresa nueva: de la bienvenida a la
 * primera orden de trabajo asignada a un técnico.
 *
 * - El estado es de la EMPRESA (companies.setup_*), no de un usuario.
 * - El avance sale de registros REALES creados con los formularios de
 *   siempre (técnico, cliente, edificio con equipos, orden con técnico): no
 *   se crea nada ficticio ni se duplica lógica. Solo tres pasos se marcan a
 *   mano: la bienvenida, «Mi empresa» guardada y «así lo ve el técnico».
 * - Solo la usa el dueño de la empresa (canUseOnboarding).
 * - Pausar es solo por la sesión: el progreso queda guardado.
 */
final class CompanySetup
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const PAUSED_SESSION_KEY = 'company_setup.paused';

    /** Pasos en orden (sin la bienvenida ni la felicitación). */
    public const STEPS = ['company', 'technician', 'client', 'building', 'work_order', 'technician_view'];

    public const ACKS = ['welcome', 'company', 'technician_view'];

    public static function appliesTo(?User $user): bool
    {
        return $user instanceof User && $user->canUseOnboarding() && $user->company !== null && $user->company->setup_status !== null;
    }

    /** ¿Se muestra ahora? (empresa nueva con la configuración pendiente y sin pausar en esta sesión). */
    public static function isActiveFor(?User $user): bool
    {
        return self::appliesTo($user)
            && $user->company->setup_status === self::PENDING
            && $user->company->hasActiveAccess()
            && ! session(self::PAUSED_SESSION_KEY);
    }

    /** Lo que hay que hacer en cada paso, con el estado real de la empresa. @return list<array> */
    public function progress(Company $company): array
    {
        $acks = (array) ($company->setup_acks ?? []);
        $id = $company->id;

        // Pocas consultas, con exists(): solo mientras la configuración está pendiente.
        $technician = User::where('company_id', $id)->where('role', 'technician')->whereNull('deleted_at')->orderBy('id')->first(['id', 'name', 'email']);
        $client = Client::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')->orderBy('id')->first(['id', 'name']);
        $building = Building::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('elevators')->whereColumn('elevators.building_id', 'buildings.id'))
            ->orderBy('id')->first(['id', 'name']);
        $order = WorkOrder::withoutGlobalScopes()->where('company_id', $id)->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('work_order_user')->join('users', 'users.id', '=', 'work_order_user.user_id')
                ->whereColumn('work_order_user.work_order_id', 'work_orders.id')->where('users.role', 'technician'))
            ->orderBy('id')->first(['id', 'building_id', 'type', 'priority', 'notes', 'status', 'created_at']);

        $steps = [
            ['key' => 'company', 'title' => 'Los datos de tu empresa', 'done' => in_array('company', $acks, true),
                'text' => 'Revisá el nombre, el contacto y el domicilio, subí tu logo y elegí tu color: salen en tus remitos y presupuestos.',
                'tip' => 'Completá «Empresa» y «Apariencia» (logo y color principal) y tocá «Guardar cambios».',
                'action' => 'Completar mis datos', 'url' => CompanySettings::getUrl(), 'route' => 'pages.company-settings'],
            ['key' => 'technician', 'title' => 'Tu primer técnico', 'done' => $technician !== null, 'record' => $technician?->name,
                'text' => 'Cargá a una persona que trabaje en la calle. Con su correo y su contraseña entra a Ascento desde el celular.',
                'tip' => 'Completá nombre, email, teléfono y una contraseña. Anotala: se la vas a pasar al técnico.',
                'action' => 'Crear el técnico', 'url' => UserResource::getUrl('create'), 'route' => 'resources.users.'],
            ['key' => 'client', 'title' => 'Tu primer cliente', 'done' => $client !== null, 'record' => $client?->name,
                'text' => 'Un consorcio, una administración o una empresa para la que trabajás.',
                'tip' => 'Con el nombre y el tipo de cliente alcanza. El contacto lo podés completar después.',
                'action' => 'Crear el cliente', 'url' => ClientResource::getUrl('create'), 'route' => 'resources.clients.'],
            ['key' => 'building', 'title' => 'Un edificio con sus ascensores', 'done' => $building !== null, 'record' => $building?->name,
                'text' => 'Cargá un edificio de ese cliente con su dirección y sus equipos.',
                'tip' => 'Elegí el cliente, buscá la dirección y, en «Ascensores y montacargas», agregá al menos un equipo con su tracción.',
                'action' => 'Crear el edificio', 'url' => BuildingResource::getUrl('create'), 'route' => 'resources.buildings.'],
            ['key' => 'work_order', 'title' => 'Tu primera orden de trabajo', 'done' => $order !== null, 'record' => $order ? 'Orden #'.$order->id : null,
                'text' => 'Creá una orden en ese edificio y asignásela a '.($technician?->name ?? 'tu técnico').'.',
                'tip' => 'Elegí el edificio, el tipo y la prioridad, y en «Técnicos asignados» elegí a '.($technician?->name ?? 'tu técnico').'.',
                'action' => 'Crear la orden', 'url' => WorkOrderResource::getUrl('create'), 'route' => 'resources.work-orders.'],
            ['key' => 'technician_view', 'title' => 'Así la ve tu técnico', 'done' => in_array('technician_view', $acks, true),
                'text' => 'Mirá cómo le aparece la orden a '.($technician?->name ?? 'tu técnico').' cuando entra con su usuario.',
                'tip' => null, 'action' => 'Ver cómo la ve', 'url' => null, 'route' => null],
        ];

        // Cada paso necesita el anterior: el actual es el primero sin hacer.
        $current = null;
        foreach ($steps as $i => $step) {
            if (! $step['done']) {
                $current = $i;
                break;
            }
        }
        foreach ($steps as $i => &$step) {
            $step['current'] = $i === $current;
            $step['available'] = $current === null || $i <= $current;
        }
        unset($step);

        return [
            'welcomed' => in_array('welcome', $acks, true),
            'steps' => $steps,
            'current' => $current,
            'done' => count(array_filter($steps, fn ($s) => $s['done'])),
            'total' => count($steps),
            'technician' => $technician,
            'order' => $order,
            'orderBuilding' => $order ? Building::withoutGlobalScopes()->where('company_id', $id)->find($order->building_id, ['id', 'name', 'address']) : null,
        ];
    }

    /** Marca un paso que no depende de un registro. */
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

    /** Termina el recorrido (solo si todo está hecho). */
    public function complete(Company $company): bool
    {
        if ($this->progress($company)['current'] !== null) {
            return false;
        }
        $company->forceFill(['setup_status' => self::COMPLETED, 'setup_completed_at' => now()])->save();
        session()->forget(self::PAUSED_SESSION_KEY);

        return true;
    }

    public function skip(Company $company): void
    {
        if ($company->setup_status === self::PENDING) {
            $company->forceFill(['setup_status' => self::SKIPPED])->save();
        }
    }

    /** Desde Ayuda: vuelve a mostrar el recorrido donde quedó. */
    public function resume(Company $company): void
    {
        if (in_array($company->setup_status, [self::SKIPPED, self::PENDING], true)) {
            $company->forceFill(['setup_status' => self::PENDING])->save();
        }
        session()->forget(self::PAUSED_SESSION_KEY);
    }

    /** Lo usa «Mi empresa» al guardar: el paso de los datos queda hecho. */
    public static function companySaved(?User $user): void
    {
        if ($user && Access::isOwner($user) && $user->company?->setup_status === self::PENDING) {
            app(self::class)->acknowledge($user->company, 'company');
        }
    }

    public static function dashboardUrl(): string
    {
        return Dashboard::getUrl();
    }
}
