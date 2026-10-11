<?php

namespace App\Models\Concerns;

use App\Support\Portal\PortalAccess;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        /*
        |--------------------------------------------------------------------------
        | ASIGNACIÓN DE EMPRESA AL CREAR
        |--------------------------------------------------------------------------
        |
        | La empresa de un registro NUNCA sale del request (hidden inputs,
        | estado de Livewire, etc.). Si hay un usuario autenticado, manda
        | su empresa (o la seleccionada por el SuperAdmin), pisando lo que
        | venga en el modelo. Sin usuario (consola, colas, webhooks) se
        | respeta el company_id explícito que haya puesto el código.
        |
        */
        static::creating(function ($model) {
            $companyId = static::currentCompanyId();

            if ($companyId !== null) {
                $model->company_id = $companyId;
            }
        });

        /*
        |--------------------------------------------------------------------------
        | LA EMPRESA ES INMUTABLE
        |--------------------------------------------------------------------------
        |
        | Un registro no puede "mudarse" a otra empresa desde una request
        | autenticada: se descarta cualquier cambio de company_id.
        |
        */
        static::updating(function ($model) {
            if (auth()->check() && $model->isDirty('company_id')) {
                $model->company_id = $model->getOriginal('company_id');
            }
        });

        static::addGlobalScope('company', function (Builder $builder) {

            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }

            if (! auth()->check()) {
                return;
            }

            $builder->where(
                $builder->getModel()->getTable().'.company_id',
                static::currentCompanyId()
            );
        });
    }

    /**
     * Empresa con la que opera el usuario autenticado: la suya, o la que
     * eligió el SuperAdmin con "Entrar". Null si no hay usuario.
     */
    protected static function currentCompanyId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $selected = session('selected_company_id');

            return $selected ? (int) $selected : null;
        }

        // Cliente del portal: la empresa activa, validada contra sus accesos.
        if ($user->isClientUser()) {
            return PortalAccess::currentCompanyId($user);
        }

        return $user->company_id ? (int) $user->company_id : null;
    }
}
