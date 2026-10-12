<?php

namespace App\Services\Crm;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Historial de contactos con clientes (Etapa 6, PR 3). Registrar o corregir
 * pide «Historial de contactos: gestionar». El cliente (y, si se indican,
 * el edificio, el presupuesto o la orden) tienen que ser de la empresa; el
 * edificio y el presupuesto, además, de ese cliente. Quién lo registró sale
 * de la sesión (nunca del pedido) y se guarda con su nombre. No se borra.
 */
class ClientInteractionService
{
    public function record(User $actor, array $data): ClientInteraction
    {
        $companyId = $this->ensureCanManage($actor);

        $interaction = new ClientInteraction($this->validated($companyId, $data));
        $interaction->forceFill(['user_id' => $actor->id, 'user_name' => Str::limit((string) $actor->name, 115, '')])->save();

        return $interaction;
    }

    public function update(User $actor, ClientInteraction $interaction, array $data): ClientInteraction
    {
        $companyId = $this->ensureCanManage($actor);
        abort_unless((int) $interaction->company_id === $companyId, 404);

        $interaction->fill($this->validated($companyId, $data))->save();

        return $interaction;
    }

    private function validated(int $companyId, array $data): array
    {
        $clean = array_intersect_key($data, array_flip(['client_id', 'building_id', 'quote_id', 'work_order_id', 'type', 'result', 'occurred_at', 'notes']));

        if (! array_key_exists($clean['type'] ?? '', ClientInteraction::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Elegí el tipo de contacto.']);
        }

        if (! array_key_exists($clean['result'] ?? '', ClientInteraction::RESULTS)) {
            throw ValidationException::withMessages(['result' => 'Elegí el resultado.']);
        }

        if (empty($clean['occurred_at'])) {
            throw ValidationException::withMessages(['occurred_at' => 'Indicá la fecha del contacto.']);
        }

        $client = Client::withoutGlobalScopes()->where('company_id', $companyId)->find($clean['client_id'] ?? 0);

        if (! $client) {
            throw ValidationException::withMessages(['client_id' => 'Ese cliente no es de tu empresa.']);
        }

        if (! empty($clean['building_id']) && ! Building::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $client->id)->whereKey($clean['building_id'])->exists()) {
            throw ValidationException::withMessages(['building_id' => 'Ese edificio no es de este cliente.']);
        }

        if (! empty($clean['quote_id']) && ! Quote::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($clean['quote_id'])->exists()) {
            throw ValidationException::withMessages(['quote_id' => 'Ese presupuesto no es de tu empresa.']);
        }

        if (! empty($clean['work_order_id']) && ! WorkOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($clean['work_order_id'])->exists()) {
            throw ValidationException::withMessages(['work_order_id' => 'Esa orden no es de tu empresa.']);
        }

        $clean['notes'] = isset($clean['notes']) ? Str::limit(trim((string) $clean['notes']), 5000, '') : null;

        return $clean;
    }

    private function ensureCanManage(User $actor): int
    {
        abort_unless(Access::can($actor, PermissionArea::Contacts, PermissionArea::MANAGE), 403);
        $id = $actor->isSuperAdmin() ? CompanyContext::currentId() : $actor->company_id;
        abort_unless($id, 403);

        return (int) $id;
    }
}
