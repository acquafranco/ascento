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

            // 🔐 admin ve todo
            if ($authUser->role === 'admin') {
                return true;
            }

            // 👤 usuario solo su propio template
            return $authUser->id === $user->id;
        });
    }
}
