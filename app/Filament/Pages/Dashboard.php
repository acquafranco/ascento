<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\FollowUpPanel;
use App\Filament\Widgets\PlatformOverviewWidget;
use App\Filament\Widgets\QuotesStatusChart;
use App\Filament\Widgets\StaffNotice;
use App\Filament\Widgets\WelcomeBanner;
use App\Filament\Widgets\WorkTrendChart;
use App\Support\Access\Access;
use Filament\Pages\Page;

class Dashboard extends Page
{
    /** Aviso a los bloques: cambiaron los permisos de quien mira. */
    public const ACCESS_CHANGED = 'dashboard-access-changed';

    /** Huella de los permisos de un administrativo al abrir el inicio (null: dueño o SuperAdmin). */
    public ?string $accessKey = null;

    protected string $view = 'filament.pages.dashboard';

    public function mount($company = null): void
    {
        $this->accessKey = static::accessKey();

        if ($company) {

            session([

                'selected_company_id' => $company,

            ]);

        }

    }

    public static function accessKey(): ?string
    {
        $user = auth()->user();

        return Access::isStaff($user) ? md5(json_encode(Access::permissionsOf($user))) : null;
    }

    /**
     * Consulta liviana y periódica (solo administrativos): si cambiaron sus
     * permisos, los bloques se actualizan y los que ya no puede ver se
     * retiran, sin recargar la página. Lo que se ve lo decide igual el
     * servidor en cada bloque.
     */
    public function checkAccess(): void
    {
        $this->skipRender();
        $key = static::accessKey();

        if ($key !== $this->accessKey) {
            $this->accessKey = $key;
            $this->dispatch(self::ACCESS_CHANGED);
        }
    }

    public static function getNavigationLabel(): string
    {
        return 'Inicio';
    }

    public function getTitle(): string
    {
        return 'Panel principal';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            WelcomeBanner::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        // SuperAdmin sin empresa elegida: el estado de la plataforma (los
        // números de una empresa quedarían en 0). Con "Entrar", los de esa empresa.
        if (auth()->user()?->isSuperAdmin() && ! session('selected_company_id')) {
            return [PlatformOverviewWidget::class];
        }

        // Cada bloque decide si se ve según las áreas de quien mira (dueño: todos).
        return [
            StaffNotice::class,
            AdminStats::class,
            WorkTrendChart::class,
            QuotesStatusChart::class,
            FollowUpPanel::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-home';
    }

    protected function getColumns(): int|string|array
    {

        return 1;

    }
}
