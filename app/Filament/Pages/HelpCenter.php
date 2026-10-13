<?php

namespace App\Filament\Pages;

use App\Enums\PlanLimit;
use App\Support\Access\Access;
use App\Support\Help\HelpGuides;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Ayuda del panel (Etapa 9.3): guías prácticas por módulo, solo las que esta
 * persona puede usar (plan de la empresa y permisos por área; las de la
 * cuenta, solo para el dueño). Con búsqueda, filtro por módulo y enlace
 * directo a una guía (?guia=clave), que usan las ayudas de cada pantalla.
 */
class HelpCenter extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Ayuda';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'ayuda';

    protected static ?string $title = 'Ayuda';

    protected string $view = 'filament.pages.help-center';

    public string $search = '';

    public string $module = '';

    #[Url(as: 'guia')]
    public string $guide = '';

    /**
     * Dueño y SuperAdmin; un administrativo, si tiene al menos un área
     * habilitada (sin permisos no abre ninguna pantalla salvo el inicio).
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return Access::panelUser($user) && (! Access::isStaff($user) || Access::permissionsOf($user) !== []);
    }

    public function getSubheading(): ?string
    {
        return 'Guías paso a paso de lo que podés hacer en Ascento con tu plan y tus permisos.';
    }

    /** @return array<string, list<array>> */
    public function guides(): array
    {
        $search = mb_strtolower(trim($this->search));
        $guides = array_filter(HelpGuides::for(auth()->user(), HelpGuides::PANEL), function (array $g) use ($search) {
            if ($this->module !== '' && $g['module'] !== $this->module) {
                return false;
            }

            return $search === '' || str_contains(mb_strtolower($g['title'].' '.$g['summary'].' '.implode(' ', $g['steps']).' '.implode(' ', $g['notes'])), $search);
        });

        return HelpGuides::grouped(array_values($guides));
    }

    /** Uso del plan, solo para el dueño (lo mismo que «Mi suscripción»). @return list<string>|null */
    public function planUsage(): ?array
    {
        $user = auth()->user();
        $company = PlanUpsell::currentCompany();
        if (! $company || ! (Access::isOwner($user) || $user?->isSuperAdmin())) {
            return null;
        }
        $guard = PlanGuard::for($company);

        return ['plan' => $guard->plan()->shortName(), 'lines' => array_map(fn (PlanLimit $l) => $guard->usageLabel($l), PlanLimit::cases())];
    }

    protected function getViewData(): array
    {
        return [
            'grouped' => $this->guides(),
            'modules' => array_keys(HelpGuides::grouped(HelpGuides::for(auth()->user(), HelpGuides::PANEL))),
            'usage' => $this->planUsage(),
        ];
    }
}
