<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {

            if (
                auth()->check() &&
                empty($model->company_id)
            ) {
                $model->company_id = auth()->user()->company_id;
            }
        });

        static::addGlobalScope('company', function (Builder $builder) {

                if (app()->runningInConsole()) {
                return;
                }
                if (auth()->check()){
                $builder->where(
                    $builder->getModel()->getTable() . '.company_id',
                    auth()->user()->company_id
                );
            }
        });
    }
}
