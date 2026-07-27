<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SetCompanyRouteDefaults;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'company' => \App\Http\Middleware\SetCompany::class,
            'company.defaults' => SetCompanyRouteDefaults::class,
        ]);

        $middleware->redirectUsersTo(function () {
            if (! auth()->check()) {
                return null;
            }

            return route('dashboard', [
                'company' => auth()->user()->company->slug,
            ]);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
