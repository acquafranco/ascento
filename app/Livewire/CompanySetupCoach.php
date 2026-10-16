<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Onboarding\CompanySetup;
use App\Support\Help\HelpTopics;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Guía de la configuración inicial (empresas nuevas). Acompaña al dueño por
 * los formularios de siempre; el avance lo da lo que realmente cargó. Nunca
 * recibe ids del navegador: todo es de la empresa del usuario autenticado.
 */
class CompanySetupCoach extends Component
{
    /** Pantalla en la que se abrió (para el consejo de esa pantalla). */
    #[Locked]
    public string $routeName = '';

    public bool $previewOpen = false;

    public function mount(?string $routeName = null): void
    {
        $this->routeName = $routeName ?? HelpTopics::currentRouteName();
    }

    private function owner(): User
    {
        $user = auth()->user();
        abort_unless(CompanySetup::appliesTo($user), 403);

        return $user;
    }

    public function start(): void
    {
        app(CompanySetup::class)->acknowledge($this->owner()->company, 'welcome');
    }

    /** Seguir más tarde: no se vuelve a abrir en esta sesión; el progreso queda guardado. */
    public function pause(): void
    {
        $this->owner();
        session()->put(CompanySetup::PAUSED_SESSION_KEY, true);
    }

    public function skip(): void
    {
        app(CompanySetup::class)->skip($this->owner()->company);
    }

    public function openPreview(): void
    {
        $this->owner();
        $this->previewOpen = true;
    }

    public function acknowledgeTechnicianView(): void
    {
        $user = $this->owner();
        $progress = app(CompanySetup::class)->progress($user->company);
        // Solo con una orden real asignada a un técnico (el paso anterior).
        abort_unless($progress['order'] !== null, 422);
        app(CompanySetup::class)->acknowledge($user->company, 'technician_view');
        $this->previewOpen = false;
    }

    public function finish()
    {
        $user = $this->owner();
        abort_unless(app(CompanySetup::class)->complete($user->company), 422);

        // Sigue la ayuda de inicio: el recorrido corto del menú y los próximos pasos.
        return $this->redirect(CompanySetup::dashboardUrl().'?configuracion=lista');
    }

    public function render()
    {
        $user = auth()->user();
        $visible = CompanySetup::isActiveFor($user);
        $progress = $visible ? app(CompanySetup::class)->progress($user->company) : null;

        // Celebración breve cuando se completó un paso desde la última vez.
        $celebrate = null;
        if ($progress) {
            $seen = (int) session('company_setup.done', 0);
            if ($progress['done'] > $seen && $progress['welcomed']) {
                $celebrate = collect($progress['steps'])->filter(fn ($s) => $s['done'])->last()['title'] ?? null;
            }
            session()->put('company_setup.done', $progress['done']);
        }

        $company = $user?->company;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $company?->primary_color) ? $company->primary_color : '#F59E0B';

        return view('livewire.company-setup-coach', [
            'visible' => $visible,
            'progress' => $progress,
            'celebrate' => $celebrate,
            'company' => $company,
            'color' => $color,
            'logo' => $company?->logo ? asset('storage/'.$company->logo) : null,
            'here' => $progress && $progress['current'] !== null
                ? (($r = $progress['steps'][$progress['current']]['route']) && str_contains($this->routeName, $r))
                : false,
        ]);
    }
}
