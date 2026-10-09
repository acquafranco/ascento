<?php

namespace App\Support;

use App\Support\Portal\PortalAccess;

class CompanyContext
{
    public static function set($companyId): void
    {
        session([
            'selected_company_id' => $companyId,
        ]);
    }

    public static function get()
    {
        return session('selected_company_id');
    }

    /**
     * Empresa con la que opera el usuario autenticado: la propia, o la
     * elegida con "Entrar" si es SuperAdmin.
     */
    public static function currentId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $companyId = match (true) {
            $user->isSuperAdmin() => static::get(),
            $user->isClientUser() => PortalAccess::currentCompanyId($user),
            default => $user->company_id,
        };

        return $companyId ? (int) $companyId : null;
    }

    public static function clear(): void
    {
        session()->forget('selected_company_id');
    }
}
