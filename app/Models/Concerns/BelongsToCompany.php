<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {

            if (! auth()->check() || ! empty($model->company_id)) {
                return;
            }

            if (auth()->user()->isSuperAdmin()) {
                $model->company_id = session('selected_company_id');
            } else {
                $model->company_id = auth()->user()->company_id;
            }
        });

        static::addGlobalScope('company', function (Builder $builder) {

    if (app()->runningInConsole()) {
        return;
    }

    if (auth()->check()) {

        $companyId = auth()->user()->company_id;

        if (auth()->user()->isSuperAdmin() && session('selected_company_id')) {
            $companyId = session('selected_company_id');
        }

        $builder->where(
            $builder->getModel()->getTable() . '.company_id',
            $companyId
        );
    }
});
    }
}
