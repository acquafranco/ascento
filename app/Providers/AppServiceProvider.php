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

        // Al cerrar sesión (app del técnico o panel), ESTE dispositivo deja
        // de recibir los avisos de esa cuenta (celulares compartidos).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function (\Illuminate\Auth\Events\Logout $event) {
            $endpoint = request()->hasSession()
                ? request()->session()->get(\App\Http\Controllers\PushSubscriptionController::SESSION_KEY)
                : null;

            if ($endpoint && $event->user instanceof User) {
                $event->user->deletePushSubscription($endpoint);
            }
        });
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
