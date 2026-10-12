<?php

namespace App\Enums;

use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\Report;
use App\Models\User;

/**
 * Límites de uso de los planes. Cada caso sabe qué columna del plan lo
 * define y cómo se cuenta el uso real de una empresa.
 */
enum PlanLimit: string
{
    case Buildings = 'buildings';
    case Clients = 'clients';
    case Technicians = 'technicians';
    case ReportsPerMonth = 'reports_per_month';
    // Usuarios administrativos adicionales (Etapa 6). El dueño no cuenta.
    case AdminUsers = 'admin_users';

    /** Columna de subscription_plans (NULL = sin límite). */
    public function column(): string
    {
        return 'max_'.$this->value;
    }

    public function singular(): string
    {
        return match ($this) {
            self::Buildings => 'edificio',
            self::Clients => 'cliente',
            self::Technicians => 'técnico',
            self::ReportsPerMonth => 'reporte',
            self::AdminUsers => 'usuario administrativo',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Buildings => 'edificios',
            self::Clients => 'clientes',
            self::Technicians => 'técnicos',
            self::ReportsPerMonth => 'reportes este mes',
            self::AdminUsers => 'usuarios administrativos',
        };
    }

    /** Para textos de plan: "Hasta 20 edificios", "15 reportes por mes". */
    public function planLabel(?int $max): string
    {
        if ($max === null) {
            return match ($this) {
                self::ReportsPerMonth => 'Reportes sin límite mensual',
                default => ucfirst($this->plural()).' sin límite',
            };
        }

        return match (true) {
            $this === self::AdminUsers && $max === 0 => 'Sin usuarios administrativos',
            $this === self::AdminUsers && $max === 1 => 'Hasta 1 usuario administrativo',
            $this === self::ReportsPerMonth => "Hasta {$max} reportes por mes",
            default => "Hasta {$max} ".$this->plural(),
        };
    }

    public function isMonthly(): bool
    {
        return $this === self::ReportsPerMonth;
    }

    /**
     * Uso actual de la empresa. Sin scopes globales: se filtra explícito por
     * company_id (funciona igual en requests, jobs y consola).
     */
    public function usage(Company $company): int
    {
        return match ($this) {
            // Los desactivados (soft delete) no ocupan cupo; reactivarlos lo chequea.
            self::Buildings => Building::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            self::Clients => Client::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            // Solo usuarios operativos: ni admins ni SuperAdmin.
            self::Technicians => User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->whereNotIn('role', ['admin', 'client', 'staff']) // ni admins ni usuarios del portal
                ->where('is_super_admin', false)
                ->count(),
            // Incluye los eliminados: borrar un reporte no devuelve cupo. Los
            // reportes de inspección son parte de la inspección: no cuentan.
            self::ReportsPerMonth => Report::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('kind', Report::KIND_INCIDENT)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            // Administrativos activos (los desactivados no ocupan lugar).
            self::AdminUsers => User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->where('role', User::ROLE_STAFF)
                ->where('is_super_admin', false)
                ->count(),
        };
    }
}
