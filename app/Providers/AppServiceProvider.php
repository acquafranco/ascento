<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('es');
        Gate::define('view-user-template', function (User $authUser, User $user) {

            // Nunca entre empresas distintas.
            if ($authUser->company_id === null || $authUser->company_id !== $user->company_id) {
                return false;
            }

            // El admin ve las plantillas de su empresa; el resto, solo la propia.
            return $authUser->isAdmin() || $authUser->id === $user->id;
        });
    }
}
