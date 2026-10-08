<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SetCompanyRouteDefaults;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'company' => \App\Http\Middleware\SetCompany::class,
            'company.defaults' => SetCompanyRouteDefaults::class,
            'subscription' => \App\Http\Middleware\EnsureActiveSubscription::class,
        ]);

        $middleware->redirectUsersTo(function () {
            if (! auth()->check()) {
                return null;
            }

            // Ya logueado y pide /login: a su pantalla, o al login limpio
            // si su cuenta no tiene a dónde entrar (nunca un 403).
            return \App\Support\HomeRedirect::url();
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Límites y funciones del plan: mensaje claro + "Ver planes", nunca
        // un error genérico (ver PlanGuard / ConsumesPlanLimit).
        $exceptions->render(function (\App\Exceptions\PlanLimitReachedException|\App\Exceptions\PlanFeatureUnavailableException $e, \Illuminate\Http\Request $request) {
            $isLimit = $e instanceof \App\Exceptions\PlanLimitReachedException;
            $upgradeUrl = $isLimit
                ? \App\Support\Plans\PlanUpsell::url($e->limit)
                : \App\Support\Plans\PlanUpsell::url(feature: $e->feature);

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => $e->getMessage(), 'upgrade_url' => $upgradeUrl], $isLimit ? 422 : 403);
            }

            if ($request->user()?->isAdmin()) {
                ($isLimit
                    ? \App\Support\Plans\PlanUpsell::limitNotification($e->company, $e->limit)
                    : \App\Support\Plans\PlanUpsell::featureNotification($e->company, $e->feature))->send();

                return redirect()->to($upgradeUrl);
            }

            // Técnicos: no pueden cambiar el plan; se les explica a quién avisar.
            return back()->withInput()->with('error', $e->getMessage().' Avisale al administrador de tu empresa.');
        });
    })
    ->create();
