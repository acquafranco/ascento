<?php

namespace App\Services\Buildings;

use App\Models\Building;
use App\Models\BuildingVisitPlan;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Notifications\AssignmentNotifier;
use App\Services\Visits\VisitPlanService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asignación de técnicos (y, opcionalmente, frecuencia de visitas) a varios
 * edificios a la vez, con los MISMOS límites que la asignación individual:
 * hasta 2 técnicos de mantenimiento y 1 inspector por edificio.
 *
 * - plan(): el resumen previo, sin escribir nada (qué cambia en cada
 *   edificio, cuáles ya tienen técnico y cuáles se saltean y por qué).
 * - apply(): vuelve a calcular todo en el servidor (nunca confía en el
 *   resumen que vio el navegador) y aplica en UNA transacción: si algo falla
 *   a mitad de camino no queda nada a medias. Los edificios que no se pueden
 *   modificar se saltean; los avisos a los técnicos salen después de guardar.
 *
 * Modos: agregar (suma a los actuales), reemplazar (deja exactamente los
 * elegidos) y conservar (solo asigna donde no hay nadie de ese tipo).
 */
class BulkAssignmentService
{
    public const LIMITS = ['maintenance' => 2, 'inspection' => 1];

    public const MODES = [
        'add' => 'Agregar a los actuales',
        'replace' => 'Reemplazar a los actuales',
        'keep' => 'Conservar los actuales (solo asignar donde no hay nadie)',
    ];

    public const MAX_BUILDINGS = 500;

    public function __construct(
        private VisitPlanService $plans,
        private AssignmentNotifier $notifier,
    ) {}

    /**
     * @param  array{type: string, user_ids?: array, mode: string, frequency?: ?string, every_months?: mixed, starts_on?: ?string}  $input
     * @return array{type: string, mode: string, technicians: Collection, frequency: ?array, rows: list<array>, counts: array{change: int, unchanged: int, skipped: int}}
     */
    public function plan(int $companyId, iterable $buildingIds, array $input): array
    {
        [$type, $mode, $technicians, $frequency] = $this->validated($companyId, $input);

        $ids = collect($buildingIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['buildings' => 'Elegí al menos un edificio.']);
        }

        if ($ids->count() > self::MAX_BUILDINGS) {
            throw ValidationException::withMessages(['buildings' => 'Podés asignar hasta '.self::MAX_BUILDINGS.' edificios por vez.']);
        }

        $buildings = Building::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->with(['users' => fn ($q) => $q->withTrashed()->select('users.id', 'users.name')])
            ->orderBy('name')
            ->get();

        $limit = self::LIMITS[$type];
        $chosen = $technicians->pluck('id')->all();
        $rows = [];

        foreach ($buildings as $building) {
            $current = $building->users->filter(fn ($u) => $u->pivot->type === $type)->values();
            $currentIds = $current->pluck('id')->map(fn ($id) => (int) $id)->all();
            $row = [
                'building_id' => $building->id,
                'name' => $building->name,
                'address' => $building->address,
                'current' => $current->pluck('name')->all(),
                'result' => $current->pluck('name')->all(),
                'attach' => [],
                'detach' => [],
                'frequency' => $frequency !== null,
                'frequency_from' => null,
                'frequency_note' => null,
                'outcome' => 'unchanged',
                'reason' => null,
            ];

            if ($building->trashed()) {
                $rows[] = [...$row, 'outcome' => 'skipped', 'reason' => 'El edificio está desactivado.', 'frequency' => false];

                continue;
            }

            // Desde qué mes rige de verdad en ESTE edificio: si el mes elegido
            // cae en medio de un período de varios meses, desde el siguiente.
            if ($frequency !== null) {
                $from = $this->plans->effectiveStart($building, $type, $frequency['start']);
                $row['frequency_from'] = $from->format('Y-m');
                $row['frequency_note'] = $from->equalTo($frequency['start']->startOfMonth()) ? null
                    : 'La frecuencia rige desde '.$from->locale('es')->translatedFormat('F Y').' (no corta el período en curso).';
            }

            if ($chosen !== []) {
                $target = match ($mode) {
                    'add' => array_values(array_unique([...$currentIds, ...$chosen])),
                    'replace' => $chosen,
                    'keep' => $currentIds === [] ? $chosen : $currentIds,
                };

                if (count($target) > $limit) {
                    $rows[] = [...$row, 'outcome' => 'skipped', 'frequency' => false, 'reason' => $type === 'inspection'
                        ? 'Ya tiene inspector y solo puede haber uno.'
                        : 'Superaría el límite de '.$limit.' técnicos de mantenimiento (ya tiene '.count($currentIds).').'];

                    continue;
                }

                $row['attach'] = array_values(array_diff($target, $currentIds));
                $row['detach'] = array_values(array_diff($currentIds, $target));
                $row['result'] = collect($target)->map(fn ($id) => $technicians->firstWhere('id', $id)?->name ?? $current->firstWhere('id', $id)?->name)->filter()->values()->all();

                if ($mode === 'keep' && $currentIds !== []) {
                    $row['reason'] = 'Ya tiene técnico: se conserva.';
                } elseif ($row['attach'] === [] && $row['detach'] === []) {
                    $row['reason'] = 'Ya tiene exactamente esos técnicos.';
                }
            }

            if ($row['attach'] !== [] || $row['detach'] !== []) {
                $row['outcome'] = 'change';
            }

            $rows[] = $row;
        }

        // Ids elegidos que no son edificios de esta empresa: nunca se tocan.
        foreach ($ids->diff($buildings->pluck('id')) as $missing) {
            $rows[] = ['building_id' => $missing, 'name' => 'Edificio #'.$missing, 'address' => null, 'current' => [], 'result' => [], 'attach' => [], 'detach' => [], 'frequency' => false, 'outcome' => 'skipped', 'reason' => 'No es un edificio de tu empresa.'];
        }

        return [
            'type' => $type,
            'mode' => $mode,
            'technicians' => $technicians,
            'frequency' => $frequency,
            'rows' => $rows,
            'counts' => [
                'change' => collect($rows)->where('outcome', 'change')->count(),
                'unchanged' => collect($rows)->where('outcome', 'unchanged')->count(),
                'skipped' => collect($rows)->where('outcome', 'skipped')->count(),
            ],
        ];
    }

    /**
     * Huella del resumen: qué se agrega, quita y saltea en cada edificio y la
     * frecuencia. Si al confirmar no coincide con la del resumen que vio el
     * admin (otro admin cambió algo en el medio), no se aplica nada.
     */
    public static function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode([
            $plan['type'], $plan['mode'],
            $plan['frequency'] ? [$plan['frequency']['per_month'], $plan['frequency']['every'], $plan['frequency']['start']->format('Y-m')] : null,
            collect($plan['rows'])->map(fn ($r) => [$r['building_id'], $r['outcome'], $r['attach'], $r['detach'], $r['frequency'], $r['frequency_from'] ?? null])->sortBy(0)->values()->all(),
        ]));
    }

    /**
     * Aplica en una transacción y devuelve el resultado (mismo formato que plan()).
     * Con $expectedFingerprint (la huella del resumen), si la situación cambió
     * desde el resumen no se aplica nada y se pide revisar de nuevo.
     */
    public function apply(int $companyId, iterable $buildingIds, array $input, ?string $expectedFingerprint = null): array
    {
        $notify = [];

        $result = DB::transaction(function () use ($companyId, $buildingIds, $input, $expectedFingerprint, &$notify) {
            // Bloquea los edificios: dos admins a la vez no pasan los límites.
            Building::withoutGlobalScopes()->withTrashed()->where('company_id', $companyId)
                ->whereIn('id', collect($buildingIds)->map(fn ($id) => (int) $id)->all())
                ->lockForUpdate()->get(['id']);

            $plan = $this->plan($companyId, $buildingIds, $input);
            $type = $plan['type'];

            if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, self::fingerprint($plan))) {
                throw ValidationException::withMessages(['buildings' => 'Las asignaciones cambiaron desde el resumen (¿otro admin las modificó?). No se aplicó nada: volvé a abrir la asignación y revisá el resumen.']);
            }

            foreach ($plan['rows'] as $row) {
                if ($row['outcome'] === 'skipped') {
                    continue;
                }

                $building = Building::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($row['building_id']);

                foreach ($row['detach'] as $userId) {
                    if ($building->users()->wherePivot('type', $type)->detach($userId)) {
                        $notify[] = [$building, (int) $userId, $type, false];
                    }
                }

                foreach ($row['attach'] as $userId) {
                    $building->users()->attach($userId, ['type' => $type]);
                    $notify[] = [$building, (int) $userId, $type, true];
                }

                if ($plan['frequency']) {
                    $this->plans->set($building, $type, $plan['frequency']['per_month'], $plan['frequency']['every'], $plan['frequency']['start']);
                }
            }

            return $plan;
        });

        // Registro de actividad: un evento por edificio con lo que cambió.
        foreach (collect($notify)->groupBy(fn ($n) => $n[0]->id) as $changes) {
            ActivityLogger::record('assignment.bulk_applied', $changes->first()[0], [
                'type' => $changes->first()[2],
                'added' => $changes->filter(fn ($n) => $n[3])->map(fn ($n) => $n[1])->values()->all(),
                'removed' => $changes->reject(fn ($n) => $n[3])->map(fn ($n) => $n[1])->values()->all(),
            ]);
        }

        // Avisos solo después de confirmar los cambios en la base. Un aviso
        // que falla no deshace ni corta la asignación (ya está guardada) ni
        // impide los avisos a los demás técnicos: queda en el log.
        $result['notices_failed'] = 0;
        foreach ($notify as [$building, $userId, $type, $assigned]) {
            try {
                $this->notifier->building($building, $userId, $type, $assigned);
            } catch (\Throwable $e) {
                $result['notices_failed']++;
                report($e);
            }
        }

        return $result;
    }

    /** @return array{0: string, 1: string, 2: Collection<int, User>, 3: ?array} */
    private function validated(int $companyId, array $input): array
    {
        $type = (string) ($input['type'] ?? '');
        $mode = (string) ($input['mode'] ?? 'add');

        if (! array_key_exists($type, self::LIMITS)) {
            throw ValidationException::withMessages(['type' => 'Elegí mantenimiento o inspección.']);
        }

        if (! array_key_exists($mode, self::MODES)) {
            throw ValidationException::withMessages(['mode' => 'Elegí cómo aplicar la asignación.']);
        }

        $userIds = collect($input['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($userIds->count() > self::LIMITS[$type]) {
            throw ValidationException::withMessages(['user_ids' => $type === 'inspection'
                ? 'Solo puede haber un inspector por edificio.'
                : 'Solo pueden asignarse hasta dos técnicos de mantenimiento.']);
        }

        // Solo técnicos activos de ESTA empresa (nunca admins, clientes ni otra empresa).
        $technicians = User::query()
            ->where('company_id', $companyId)
            ->where('role', 'technician')
            ->where('is_super_admin', false)
            ->whereIn('id', $userIds->all() ?: [0])
            ->get(['id', 'name']);

        if ($technicians->count() !== $userIds->count()) {
            throw ValidationException::withMessages(['user_ids' => 'Uno de los técnicos elegidos no es válido.']);
        }

        $frequency = null;
        if (filled($input['frequency'] ?? null) && $input['frequency'] !== 'keep') {
            [$perMonth, $every, $start] = VisitPlanService::normalize((string) $input['frequency'], $input['every_months'] ?? null, $input['starts_on'] ?? null);
            $frequency = ['per_month' => $perMonth, 'every' => $every, 'start' => $start, 'label' => BuildingVisitPlan::labelFor($perMonth, $every), 'start_label' => ucfirst($start->locale('es')->translatedFormat('F Y'))];
        }

        if ($technicians->isEmpty() && $frequency === null) {
            throw ValidationException::withMessages(['user_ids' => 'Elegí al menos un técnico o una frecuencia para cambiar.']);
        }

        return [$type, $mode, $technicians, $frequency];
    }
}
