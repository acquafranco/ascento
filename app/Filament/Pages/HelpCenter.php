<?php

namespace App\Filament\Pages;

use App\Enums\PlanLimit;
use App\Services\Onboarding\CompanySetup;
use App\Support\Access\Access;
use App\Support\Help\HelpGuides;
use App\Support\Help\HelpSearch;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Centro de ayuda del panel: buscador, categorías por tarea, artículos paso
 * a paso y preguntas frecuentes. Solo muestra lo que esta persona puede usar
 * (plan de la empresa, permisos por área; lo de la cuenta, solo el dueño; lo
 * de la plataforma, solo el SuperAdmin).
 *
 * Enlaces directos: ?guia=clave (abre el artículo), ?tema=categoría y
 * ?desde=/admin/… (vuelve a la pantalla desde la que se abrió la ayuda).
 */
class HelpCenter extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Ayuda';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'ayuda';

    protected static ?string $title = 'Centro de ayuda';

    protected string $view = 'filament.pages.help-center';

    public string $search = '';

    /** Filtro por categoría (su título). */
    public string $module = '';

    #[Url(as: 'guia')]
    public string $guide = '';

    #[Url(as: 'tema')]
    public string $topic = '';

    #[Url(as: 'desde')]
    public string $from = '';

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
        return 'Encontrá cómo hacer cada tarea en Ascento, con tu plan y tus permisos.';
    }

    /** Artículos visibles, filtrados por búsqueda y categoría, agrupados. @return array<string, list<array>> */
    public function guides(): array
    {
        return HelpGuides::grouped($this->results());
    }

    /** Lo mismo, en una lista ordenada por relevancia cuando hay búsqueda. @return list<array> */
    public function results(): array
    {
        $guides = HelpGuides::for(auth()->user(), HelpGuides::PANEL);

        if ($this->module !== '') {
            $guides = array_values(array_filter($guides, fn (array $g) => $g['module'] === $this->module));
        }

        return trim($this->search) === '' ? $guides : HelpSearch::search($guides, $this->search);
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

    /** Dueño de una empresa nueva: retomar la configuración inicial guiada. */
    public function canResumeSetup(): bool
    {
        $user = auth()->user();

        return CompanySetup::appliesTo($user)
            && in_array($user->company->setup_status, [CompanySetup::PENDING, CompanySetup::SKIPPED], true);
    }

    public function resumeSetup()
    {
        abort_unless($this->canResumeSetup(), 403);
        app(CompanySetup::class)->resume(auth()->user()->company);

        return $this->redirect(CompanySetup::wizardUrl());
    }

    public function showTopic(string $slug): void
    {
        $this->topic = $slug;
        $this->guide = '';
        $this->search = '';
    }

    public function home(): void
    {
        $this->reset('topic', 'guide', 'search', 'module');
    }

    /** Solo una dirección interna del panel (nunca otra web): para «Volver a donde estabas». */
    public function backUrl(): ?string
    {
        $from = $this->from;
        $panel = '/'.trim((string) filament()->getCurrentPanel()?->getPath(), '/').'/';

        return $from !== '' && str_starts_with($from, $panel) && ! preg_match('#//|\\\\|[\x00-\x1f]#', $from) && strlen($from) <= 300
            ? $from : null;
    }

    /** Artículos destacados según el perfil (los primeros que esta persona puede usar). @return list<array> */
    public function featured(): array
    {
        $user = auth()->user();
        $keys = match (true) {
            (bool) $user?->isSuperAdmin() => ['sa_companies', 'sa_subscriptions', 'nav'],
            Access::isOwner($user) => ['start', 'nav', 'building_create', 'work_orders', 'service_contract', 'portal'],
            default => ['nav', 'help_button', 'work_orders', 'client_create', 'agenda', 'receivables', 'tasks'],
        };

        return array_slice(array_values(array_filter(array_map(fn ($k) => HelpGuides::find($user, HelpGuides::PANEL, $k), $keys))), 0, 4);
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $all = HelpGuides::for($user, HelpGuides::PANEL);
        $categories = HelpGuides::categoriesFor($user, HelpGuides::PANEL);
        $current = $this->guide !== '' ? HelpGuides::find($user, HelpGuides::PANEL, $this->guide) : null;
        $topic = $current['category'] ?? ($this->topic !== '' && isset($categories[$this->topic]) ? $this->topic : null);
        $searching = trim($this->search) !== '' || $this->module !== '';
        $faq = HelpGuides::faqFor($user, HelpGuides::PANEL);

        return [
            'mode' => $searching ? 'search' : ($topic ? 'topic' : 'home'),
            'results' => $searching ? $this->results() : [],
            'faqResults' => trim($this->search) !== '' && $this->module === '' ? HelpSearch::search($faq, $this->search) : [],
            'categories' => $categories,
            'topicSlug' => $topic,
            'current' => $current,
            'faq' => $faq,
            'featured' => $this->featured(),
            'modules' => array_keys(HelpGuides::grouped($all)),
            'usage' => $this->planUsage(),
            'back' => $this->backUrl(),
            'total' => count($all),
        ];
    }
}
