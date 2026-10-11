<?php

namespace App\Support;

use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ficha de una empresa para el SuperAdmin: estados separados (no se mezclan)
 * y uso real, todo contado desde la base. Nada estimado ni inventado.
 */
class CompanyHealth
{
    /**
     * @return array{
     *   registered_at: ?CarbonInterface,
     *   owner: ?User,
     *   email_verified: bool, email_verified_at: ?CarbonInterface,
     *   account: array{key: string, label: string},
     *   subscription: array{key: string, label: string},
     *   usage: array<string, int>,
     *   last_activity: ?CarbonInterface
     * }
     */
    public static function for(Company $company): array
    {
        $id = $company->id;
        $owner = $company->owner()->first();
        $count = fn (string $model) => $model::withoutGlobalScopes()->where('company_id', $id);

        $account = match (true) {
            $company->trashed() => ['key' => 'overdue', 'label' => 'Desactivada'],
            ! $company->is_active => ['key' => 'overdue', 'label' => 'Deshabilitada'],
            default => ['key' => 'done', 'label' => 'Habilitada'],
        };

        $roles = User::where('company_id', $id)->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        $last = collect([
            $count(Report::class)->max('created_at'),
            $count(DeliveryNote::class)->max('created_at'),
            $count(WorkOrder::class)->max('updated_at'),
        ])->filter()->map(fn ($d) => Carbon::parse($d))->max();

        return [
            'registered_at' => $company->created_at,
            'owner' => $owner,
            'email_verified' => (bool) $owner?->hasVerifiedEmail(),
            'email_verified_at' => $owner?->email_verified_at,
            'account' => $account,
            'subscription' => ['key' => SubscriptionStatus::key($company), 'label' => SubscriptionStatus::label($company)],
            'usage' => [
                'Administradores' => (int) ($roles['admin'] ?? 0),
                'Técnicos' => (int) ($roles['technician'] ?? 0),
                'Usuarios del portal' => (int) ($roles['client'] ?? 0),
                'Clientes' => $count(Client::class)->count(),
                'Edificios' => $count(Building::class)->count(),
                'Reportes este mes' => $count(Report::class)->where('created_at', '>=', now()->startOfMonth())->count(),
                'Remitos este mes' => $count(DeliveryNote::class)->where('created_at', '>=', now()->startOfMonth())->count(),
                'Órdenes abiertas' => $count(WorkOrder::class)->whereIn('status', ['pending', 'in_progress'])->count(),
            ],
            'last_activity' => $last,
        ];
    }
}
