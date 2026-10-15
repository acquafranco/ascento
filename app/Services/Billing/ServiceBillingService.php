<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genera las obligaciones periódicas de los servicios activos. Idempotente:
 * UNIQUE (servicio, período) en la base + verificación previa.
 */
class ServiceBillingService
{
    public function __construct(private ReceivableService $receivables) {}

    /**
     * Períodos que corresponde cobrar hasta $upTo (inclusive, nunca futuros).
     *
     * @return list<Carbon> primer día de cada período
     */
    public function periodsFor(MaintenanceService $service, ?CarbonInterface $upTo = null): array
    {
        $upTo = Carbon::parse($upTo ?? today())->startOfMonth();
        $cursor = Carbon::parse($service->start_date)->startOfMonth();
        $from = Carbon::parse($service->billing_from ?? $service->start_date)->startOfMonth();
        $end = $service->end_date ? Carbon::parse($service->end_date)->startOfMonth() : null;
        $step = $service->monthsPerPeriod();

        // Pago único: un solo cobro, el del primer mes que corresponde cobrar (nunca antes de cargarlo).
        if ($service->frequency === 'once') {
            return $from->lte($upTo) && (! $end || $from->lte($end)) ? [$from->copy()] : [];
        }

        $periods = [];

        // Se respeta el "ritmo" de la frecuencia desde el inicio del servicio.
        while ($cursor->lte($upTo) && (! $end || $cursor->lte($end))) {
            if ($cursor->gte($from)) {
                $periods[] = $cursor->copy();
            }

            $cursor->addMonthsNoOverflow($step);
        }

        return $periods;
    }

    /** @return int obligaciones creadas */
    public function generate(MaintenanceService $service, ?CarbonInterface $upTo = null): int
    {
        if ($service->status !== MaintenanceService::ACTIVE || $service->trashed()) {
            return 0;
        }

        // Con un usuario autenticado, BelongsToCompany asigna su empresa a lo
        // que se crea: nunca generar cobros de un servicio de otra empresa.
        $context = CompanyContext::currentId();

        if ($context !== null && $context !== (int) $service->company_id) {
            return 0;
        }

        $client = Client::withoutGlobalScopes()->withTrashed()->find($service->client_id);

        if (! $client || (int) $client->company_id !== (int) $service->company_id) {
            return 0;
        }

        // Pago único: un solo cobro en toda su vida, aunque se pause, se reactive o cambien sus fechas.
        if ($service->frequency === 'once' && Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id)->exists()) {
            return 0;
        }

        $created = 0;
        // ¿Es el primer cobro de la contratación? (para la regla de su vencimiento)
        $first = ! Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id)->exists();

        foreach ($this->periodsFor($service, $upTo) as $period) {
            $exists = Receivable::withoutGlobalScopes()
                ->where('maintenance_service_id', $service->id)
                ->whereDate('period_start', $period)
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                DB::transaction(fn () => $this->receivables->create(
                    $client,
                    $service->building_id,
                    'service',
                    $service->description.' — '.ucfirst($period->locale('es')->translatedFormat('F Y')),
                    (float) $service->amount,
                    $this->dueDateFor($service, $period, $first, today()),
                    auth()->user(),
                    serviceId: $service->id,
                    periodStart: $period,
                ));
                $created++;
                $first = false;
            } catch (UniqueConstraintViolationException) {
                // Otro proceso lo generó al mismo tiempo: ya existe.
            }
        }

        return $created;
    }

    /**
     * Vencimiento de un cobro de período: el día pactado del mes en que empieza el
     * período. El PRIMER cobro de una contratación, si al emitirse ese día ya pasó,
     * vence ese día del mes siguiente (no nace vencido). Los demás (incluida la
     * reactivación) vencen el día pactado aunque ya haya pasado.
     */
    public function dueDateFor(MaintenanceService $service, CarbonInterface $period, bool $first, CarbonInterface $issuedOn): Carbon
    {
        $day = max(1, min(28, (int) $service->payment_due_day));
        $due = Carbon::parse($period)->startOfMonth()->day($day);

        return $first && $due->lt(Carbon::parse($issuedOn)->startOfDay()) ? $due->addMonthNoOverflow() : $due;
    }

    /**
     * Al editar la contratación (día de vencimiento o inicio), ajusta solo sus cobros
     * de período pendientes y sin pagos:
     * - con el nuevo inicio, los de períodos anteriores a él se anulan (con motivo, no se borran);
     * - los demás toman el vencimiento con el nuevo día pactado (mismo período y misma regla
     *   que al emitirlos). Si con esa fecha siguen vencidos, siguen vencidos.
     * Nunca toca importes, cobros pagados, parcialmente pagados o anulados, ni el cobro
     * nuevo de «Volver a cobrar».
     *
     * @return array{rescheduled: int, voided: int}
     */
    public function syncPendingCharges(MaintenanceService $service): array
    {
        $result = ['rescheduled' => 0, 'voided' => 0];
        $all = Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id);
        $firstId = (clone $all)->min('id');
        $start = Carbon::parse($service->start_date)->startOfMonth();

        $pending = (clone $all)->where('status', Receivable::PENDING)->where('paid_amount', 0)->whereNotNull('period_start')->orderBy('period_start')->get();

        foreach ($pending as $receivable) {
            if ($receivable->period_start->lt($start)) {
                // Se vuelve a verificar con bloqueo: si entretanto recibió un pago o alguien lo anuló,
                // no se toca (ni se pisa el motivo de una anulación hecha a mano).
                $voided = DB::transaction(function () use ($receivable, $service) {
                    $locked = Receivable::withoutGlobalScopes()->lockForUpdate()->find($receivable->id);
                    if (! $locked || $locked->status !== Receivable::PENDING || (float) $locked->paid_amount > 0) {
                        return false;
                    }
                    $this->receivables->void($locked, 'La contratación ahora empieza el '.Carbon::parse($service->start_date)->format('d/m/Y').': este período quedó fuera.');

                    return true;
                });
                $result['voided'] += (int) $voided;

                continue;
            }

            $due = $this->dueDateFor($service, $receivable->period_start, (int) $receivable->id === (int) $firstId, $receivable->created_at ?? today());
            if ($this->receivables->reschedule($receivable, $due)) {
                $result['rescheduled']++;
            }
        }

        return $result;
    }

    /**
     * ¿Se puede volver a cobrar este pago único? Solo si ya tuvo cobro, todos sus
     * cobros están anulados (ninguno pendiente, vencido, parcial o pagado) y la
     * contratación no está pausada.
     */
    public function canRebill(MaintenanceService $service): bool
    {
        if ($service->frequency !== 'once' || $service->status === MaintenanceService::PAUSED || $service->trashed()) {
            return false;
        }

        $receivables = Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id);

        return (clone $receivables)->exists() && ! (clone $receivables)->where('status', '!=', Receivable::VOID)->exists();
    }

    /** Vencimiento del nuevo cobro: el día pactado de este mes o, si ya pasó, del siguiente. */
    public function rebillDueDate(MaintenanceService $service): Carbon
    {
        $day = max(1, min(28, (int) $service->payment_due_day));
        $due = today()->day($day);

        return $due->lt(today()) ? today()->startOfMonth()->addMonthNoOverflow()->day($day) : $due;
    }

    /**
     * «Volver a cobrar» un pago único cuyo cobro se anuló: un cobro nuevo vinculado
     * a la contratación, por su importe acordado. El anulado queda como está. Con
     * bloqueo sobre la contratación y la verificación dentro de la transacción: un
     * doble clic o dos solicitudes simultáneas no crean dos cobros.
     */
    public function rebillOneTime(MaintenanceService $service, ?User $user): Receivable
    {
        return DB::transaction(function () use ($service, $user) {
            $locked = MaintenanceService::withoutGlobalScopes()->lockForUpdate()->findOrFail($service->id);

            $context = CompanyContext::currentId();
            if ($context !== null && $context !== (int) $locked->company_id) {
                throw ValidationException::withMessages(['rebill' => 'Esa contratación no es de tu empresa.']);
            }

            if (! $this->canRebill($locked)) {
                throw ValidationException::withMessages(['rebill' => 'Solo se puede volver a cobrar un pago único cuyo cobro está anulado y que no tiene otro cobro vigente.']);
            }

            $client = Client::withoutGlobalScopes()->withTrashed()->find($locked->client_id);
            if (! $client || (int) $client->company_id !== (int) $locked->company_id) {
                throw ValidationException::withMessages(['rebill' => 'La contratación no tiene un cliente válido.']);
            }

            return $this->receivables->create(
                $client,
                $locked->building_id,
                'service',
                $locked->description.' — nuevo cobro (reemplaza el anulado)',
                (float) $locked->amount,
                $this->rebillDueDate($locked),
                $user,
                serviceId: $locked->id,
            );
        });
    }

    /** @return int obligaciones creadas */
    public function generateAll(?int $companyId = null, ?CarbonInterface $upTo = null): int
    {
        return MaintenanceService::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', MaintenanceService::ACTIVE)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get()
            ->sum(fn (MaintenanceService $service) => $this->generate($service, $upTo));
    }
}
