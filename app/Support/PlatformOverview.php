<?php

namespace App\Support;

use App\Models\Backup;
use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\ReportVideo;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resumen de la plataforma para el SuperAdmin. "real" = contado en la base;
 * "calculado" = sale de reglas (p. ej. el estado de la suscripción se deduce
 * de fechas y del último aviso de Mercado Pago).
 */
class PlatformOverview
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $companies = Company::withTrashed()->with(['latestSubscription', 'owner'])->get();
        $live = $companies->filter(fn (Company $c) => ! $c->trashed());
        $byStatus = $live->groupBy(fn (Company $c) => SubscriptionStatus::key($c))->map->count();

        $lastBackup = Backup::where('status', Backup::COMPLETED)->latest('completed_at')->first();

        return [
            'companies' => [
                'total' => $live->count(),
                'last7' => $live->where('created_at', '>=', now()->subDays(7))->count(),
                'last30' => $live->where('created_at', '>=', now()->subDays(30))->count(),
                'unverified' => $live->filter(fn (Company $c) => $c->owner && ! $c->owner->hasVerifiedEmail())->count(),
                'disabled' => $companies->count() - $live->where('is_active', true)->count(),
            ],
            'subscriptions' => [
                'trial' => (int) ($byStatus['trial'] ?? 0),
                'trial_ending' => $live->filter(fn (Company $c) => SubscriptionStatus::key($c) === 'trial' && $c->trial_ends_at && $c->trial_ends_at->lte(now()->addDays(7)))->count(),
                'active' => (int) ($byStatus['active'] ?? 0) + (int) ($byStatus['canceled_period'] ?? 0),
                'attention' => (int) ($byStatus['past_due'] ?? 0) + (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['awaiting_payment'] ?? 0),
                'ended' => (int) ($byStatus['trial_ended'] ?? 0) + (int) ($byStatus['expired'] ?? 0) + (int) ($byStatus['canceled'] ?? 0),
            ],
            'users' => [
                'technicians' => User::where('role', 'technician')->count(),
                'portal' => User::where('role', User::ROLE_CLIENT)->count(),
            ],
            'health' => [
                'last_backup' => $lastBackup?->completed_at,
                'failed_backups' => Backup::where('status', Backup::FAILED)->where('created_at', '>=', now()->subDays(2))->count(),
                'failed_exports' => CompanyExport::withoutGlobalScopes()->where('status', CompanyExport::FAILED)->where('created_at', '>=', now()->subDays(7))->count(),
                'failed_videos' => ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::FAILED)->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
            'recent' => static::recent($live),
        ];
    }

    /** @return Collection<int, array{company: Company, status: string}> */
    private static function recent(Collection $live): Collection
    {
        return $live->sortByDesc('created_at')->take(6)->values()
            ->map(fn (Company $c) => ['company' => $c, 'status' => SubscriptionStatus::label($c)]);
    }
}
