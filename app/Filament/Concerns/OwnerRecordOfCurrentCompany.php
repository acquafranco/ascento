<?php

namespace App\Filament\Concerns;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Relation managers de datos de una empresa: el registro dueño tiene que ser
 * de la empresa actual. Filament carga el dueño desde la página (con el scope
 * de empresa) y lo bloquea (#[Locked]); esto es defensa en profundidad para
 * cualquier otro camino (Filament no verifica canViewForRecord al montar).
 */
trait OwnerRecordOfCurrentCompany
{
    public function mountOwnerRecordOfCurrentCompany(): void
    {
        abort_unless(static::ownerRecordIsOfCurrentCompany($this->getOwnerRecord()), 404);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::ownerRecordIsOfCurrentCompany($ownerRecord) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    protected static function ownerRecordIsOfCurrentCompany(Model $ownerRecord): bool
    {
        $companyId = CompanyContext::currentId();

        return $companyId !== null && (int) $ownerRecord->getAttribute('company_id') === $companyId;
    }
}
