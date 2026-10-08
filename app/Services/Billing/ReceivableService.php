<?php

namespace App\Services\Billing;

use App\Models\Building;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICO punto que crea obligaciones, registra pagos y cambia su estado.
 */
class ReceivableService
{
    public function createManual(Client $client, ?int $buildingId, string $concept, float $amount, CarbonInterface $dueDate, ?User $user, ?string $notes = null): Receivable
    {
        $this->assertBuildingOfClient($client, $buildingId);

        return $this->create($client, $buildingId, 'manual', $concept, $amount, $dueDate, $user, $notes);
    }

    /**
     * "Generar cobro" desde un presupuesto aprobado. Una sola obligación no
     * anulada por presupuesto (lock sobre el presupuesto contra el doble clic).
     */
    public function fromQuote(Quote $quote, string $concept, float $amount, CarbonInterface $dueDate, ?User $user): Receivable
    {
        return DB::transaction(function () use ($quote, $concept, $amount, $dueDate, $user) {
            $locked = Quote::withoutGlobalScopes()->lockForUpdate()->findOrFail($quote->id);

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['quote' => 'Solo se puede generar el cobro de un presupuesto aprobado.']);
            }

            if (Receivable::withoutGlobalScopes()->where('quote_id', $locked->id)->where('status', '!=', Receivable::VOID)->exists()) {
                throw ValidationException::withMessages(['quote' => 'Este presupuesto ya tiene un cobro generado.']);
            }

            $client = Client::withoutGlobalScopes()->withTrashed()->find($locked->client_id)
                ?? Client::withoutGlobalScopes()->withTrashed()->find(Building::withoutGlobalScopes()->withTrashed()->whereKey($locked->building_id)->value('client_id'));

            if (! $client || (int) $client->company_id !== (int) $locked->company_id) {
                throw ValidationException::withMessages(['quote' => 'El presupuesto no tiene un cliente válido.']);
            }

            return $this->create($client, $locked->building_id, 'quote', $concept, $amount, $dueDate, $user, null, quoteId: $locked->id);
        });
    }

    public function registerPayment(Receivable $receivable, float $amount, CarbonInterface $paidAt, string $method, ?string $notes, ?User $user): ReceivablePayment
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El importe tiene que ser mayor a cero.']);
        }

        if (! array_key_exists($method, ReceivablePayment::METHODS)) {
            throw ValidationException::withMessages(['method' => 'Medio de pago inválido.']);
        }

        return DB::transaction(function () use ($receivable, $amount, $paidAt, $method, $notes, $user) {
            $locked = Receivable::withoutGlobalScopes()->lockForUpdate()->findOrFail($receivable->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['amount' => 'Esta obligación ya está '.($locked->status === Receivable::VOID ? 'anulada' : 'pagada').'.']);
            }

            if ($amount - $locked->balance() > 0.004) {
                throw ValidationException::withMessages(['amount' => 'El pago supera el saldo pendiente ($'.number_format($locked->balance(), 2, ',', '.').').']);
            }

            $payment = new ReceivablePayment([
                'receivable_id' => $locked->id,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'method' => $method,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);
            $payment->company_id = $locked->company_id;
            $payment->save();

            $paid = round((float) $locked->paid_amount + $amount, 2);
            $locked->forceFill([
                'paid_amount' => $paid,
                'status' => $paid + 0.004 >= (float) $locked->amount ? Receivable::PAID : Receivable::PARTIAL,
            ])->save();

            $receivable->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /** Anula una obligación sin pagos (los pagos no se borran). */
    public function void(Receivable $receivable, string $reason): void
    {
        DB::transaction(function () use ($receivable, $reason) {
            $locked = Receivable::withoutGlobalScopes()->lockForUpdate()->findOrFail($receivable->id);

            if ((float) $locked->paid_amount > 0) {
                throw ValidationException::withMessages(['reason' => 'No se puede anular: ya tiene pagos registrados.']);
            }

            $locked->forceFill(['status' => Receivable::VOID, 'voided_at' => now(), 'void_reason' => $reason])->save();
            $receivable->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function create(
        Client $client,
        ?int $buildingId,
        string $source,
        string $concept,
        float $amount,
        CarbonInterface $dueDate,
        ?User $user,
        ?string $notes = null,
        ?int $serviceId = null,
        ?CarbonInterface $periodStart = null,
        ?int $quoteId = null,
    ): Receivable {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El importe tiene que ser mayor a cero.']);
        }

        $receivable = new Receivable([
            'client_id' => $client->id,
            'building_id' => $buildingId,
            'concept' => $concept,
            'amount' => $amount,
            'due_date' => $dueDate,
            'notes' => $notes,
        ]);

        $receivable->forceFill([
            'company_id' => $client->company_id,
            'source' => $source,
            'maintenance_service_id' => $serviceId,
            'quote_id' => $quoteId,
            'period_start' => $periodStart,
            'status' => Receivable::PENDING,
            'paid_amount' => 0,
            'created_by' => $user?->id,
        ])->save();

        return $receivable;
    }

    private function assertBuildingOfClient(Client $client, ?int $buildingId): void
    {
        if ($buildingId === null) {
            return;
        }

        $ok = Building::withoutGlobalScopes()
            ->whereKey($buildingId)
            ->where('company_id', $client->company_id)
            ->where('client_id', $client->id)
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages(['building_id' => 'El edificio no pertenece a ese cliente.']);
        }
    }
}
