<?php

namespace App\Providers\Filament;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\AuthenticatePanel;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Services\Telegram\TelegramService;
use App\Support\Help\HelpTopics;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AscensoresAppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('ascensores_app')
            ->path('admin')
            ->navigationGroups([

                'Gestión',

            ])
            ->colors([
                'primary' => Color::Amber,
            ])

            // Logo de la empresa si lo cargó; si no, el de Ascento (nunca el de Laravel).
            ->brandLogo(fn () => new \Illuminate\Support\HtmlString(view('filament.partials.brand', [
                'logo' => ($company = auth()->user()?->company)?->logo ? asset('storage/'.$company->logo) : null,
                'name' => $company?->name ?? 'Ascento',
            ])->render()))
            ->brandLogoHeight('2.5rem')
            ->brandName('Ascento')
            ->favicon(asset('images/brand/favicon-32.png'))

            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources'
            )

            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages'
            )

            ->pages([
                Dashboard::class,
            ])

            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets'
            )

            ->widgets([])

            // Guía de bienvenida + ayuda: solo para admins de empresa.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => auth()->user()?->canUseOnboarding()
                    ? view('filament.partials.help-button')->render()
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->user()?->canUseOnboarding()
                    ? Blade::render('@livewire(\App\Livewire\AdminOnboarding::class, [\'routeName\' => $routeName])', [
                        'routeName' => HelpTopics::currentRouteName(),
                    ])
                    : '',
            )

            // Ayuda contextual de cada pantalla: aparece hasta que el admin la
            // descarta (estado propio por ayuda; ver HelpTopics / HelpTip).
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                function (): string {
                    $key = auth()->user()?->canUseOnboarding()
                        ? HelpTopics::forRoute(HelpTopics::currentRouteName())
                        : null;

                    return $key
                        ? Blade::render('@livewire(\App\Livewire\HelpTip::class, [\'key\' => $key], key(\'help-page\'))', ['key' => $key])
                        : '';
                },
            )

            // Avisos push del admin (trabajo terminado, reportes nuevos).
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => auth()->user()?->canReceiveAdminPush() && filled(config('webpush.vapid.public_key'))
                    ? view('filament.partials.admin-push-head')->render()
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                // En todas las páginas (salvo "Mi empresa", que tiene la tarjeta
                // completa) hasta que los active o elija "Ahora no".
                fn (): string => auth()->user()?->canReceiveAdminPush()
                    && filled(config('webpush.vapid.public_key'))
                    && HelpTopics::currentRouteName() !== 'filament.ascensores_app.pages.company-settings'
                    ? view('filament.partials.admin-push', ['compact' => true])->render()
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::PAGE_END,
                fn (): string => auth()->user()?->canReceiveAdminPush() && filled(config('webpush.vapid.public_key'))
                    ? view('filament.partials.admin-push')->render()
                    : '',
                scopes: CompanySettings::class,
            )
            ->renderHook(
                PanelsRenderHook::PAGE_END,
                fn (): string => auth()->user()?->canReceiveAdminPush() && TelegramService::isConfigured()
                    ? view('filament.partials.admin-telegram')->render()
                    : '',
                scopes: CompanySettings::class,
            )

            ->globalSearch(false)

            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                EnsureActiveSubscription::class,
            ])

            ->authMiddleware([
                AuthenticatePanel::class,
            ]);
    }
}
