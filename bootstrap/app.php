<?php

use App\Exceptions\PlanFeatureUnavailableException;
use App\Exceptions\PlanLimitReachedException;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\SetCompany;
use App\Http\Middleware\SetCompanyRouteDefaults;
use App\Support\HomeRedirect;
use App\Support\Plans\PlanUpsell;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'company' => SetCompany::class,
            'company.defaults' => SetCompanyRouteDefaults::class,
            'subscription' => EnsureActiveSubscription::class,
            'portal' => EnsurePortalUser::class,
        ]);

        $middleware->redirectUsersTo(function () {
            if (! auth()->check()) {
                return null;
            }

            // Ya logueado y pide /login: a su pantalla, o al login limpio
            // si su cuenta no tiene a dónde entrar (nunca un 403).
            return HomeRedirect::url();
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Límites y funciones del plan: mensaje claro + "Ver planes", nunca
        // un error genérico (ver PlanGuard / ConsumesPlanLimit).
        $exceptions->render(function (PlanLimitReachedException|PlanFeatureUnavailableException $e, Request $request) {
            $isLimit = $e instanceof PlanLimitReachedException;
            $upgradeUrl = $isLimit
                ? PlanUpsell::url($e->limit)
                : PlanUpsell::url(feature: $e->feature);

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => $e->getMessage(), 'upgrade_url' => $upgradeUrl], $isLimit ? 422 : 403);
            }

            if ($request->user()?->isAdmin()) {
                ($isLimit
                    ? PlanUpsell::limitNotification($e->company, $e->limit)
                    : PlanUpsell::featureNotification($e->company, $e->feature))->send();

                return redirect()->to($upgradeUrl);
            }

            // Técnicos: no pueden cambiar el plan; se les explica a quién avisar.
            return back()->withInput()->with('error', $e->getMessage().' Avisale al administrador de tu empresa.');
        });

        // Envío más grande que el límite del servidor (post_max_size): en vez
        // de un 413 sin salida, vuelve al formulario con un mensaje claro.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            return back()->withErrors(['photos' => 'Las fotos pesan demasiado para enviarlas juntas. Probá con menos fotos.']);
        });
    })
    ->create();
