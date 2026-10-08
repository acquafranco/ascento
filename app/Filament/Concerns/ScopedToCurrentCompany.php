<?php

namespace App\Filament\Concerns;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recursos de Filament de datos de una empresa: filtro explícito por la
 * empresa actual (además del scope global), sin empresa → nada. Solo admins
 * y SuperAdmin (los técnicos no entran al panel).
 */
trait ScopedToCurrentCompany
{
    public static function getEloquentQuery(): Builder
    {
        $companyId = CompanyContext::currentId();
        $query = parent::getEloquentQuery();

        return $companyId
            ? $query->where($query->getModel()->getTable().'.company_id', $companyId)
            : $query->whereRaw('1 = 0');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }
}
