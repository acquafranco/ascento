<?php

namespace App\Exceptions;

use App\Enums\PlanFeature;
use App\Models\Company;
use App\Support\Plans\PlanGuard;
use RuntimeException;

class PlanFeatureUnavailableException extends RuntimeException
{
    public function __construct(public readonly Company $company, public readonly PlanFeature $feature)
    {
        parent::__construct(PlanGuard::for($company)->featureUnavailableMessage($feature));
    }
}
