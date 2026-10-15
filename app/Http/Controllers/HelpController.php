<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpGuides;
use App\Support\Help\HelpSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ayuda de la app del técnico y del portal del cliente: las guías de
 * HelpGuides para ese perfil, según el plan de su empresa, con búsqueda
 * (?q=, funciona sin JavaScript), categorías y preguntas frecuentes. El
 * acceso lo dan los middleware de cada grupo de rutas (técnico de la
 * empresa del enlace; usuario del portal con acceso vigente).
 *
 * La bienvenida de cada perfil se muestra una sola vez por persona: se
 * marca vista solo al tocar «Entendido» (help_dismissals, del usuario
 * autenticado; nunca se recibe un id del navegador).
 */
class HelpController extends Controller
{
    public const WELCOME_TECHNICIAN = 'welcome_technician';

    public const WELCOME_PORTAL = 'welcome_portal';

    public function technician(Request $request): View
    {
        abort_unless($request->user()?->role === 'technician', 403);

        return view('help.technician', $this->data($request, HelpGuides::TECHNICIAN));
    }

    public function portal(Request $request): View
    {
        return view('portal.help', $this->data($request, HelpGuides::PORTAL));
    }

    public function dismissTechnicianWelcome(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === 'technician', 403);
        $request->user()->dismissHelp(self::WELCOME_TECHNICIAN);

        return back();
    }

    public function dismissPortalWelcome(Request $request): RedirectResponse
    {
        $request->user()->dismissHelp(self::WELCOME_PORTAL);

        return back();
    }

    private function data(Request $request, string $audience): array
    {
        $user = $request->user();
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $guides = HelpGuides::for($user, $audience);
        $faq = HelpGuides::faqFor($user, $audience);

        return [
            'grouped' => HelpGuides::grouped($query === '' ? $guides : HelpSearch::search($guides, $query)),
            'query' => $query,
            'results' => $query === '' ? null : HelpSearch::search($guides, $query),
            'faq' => $query === '' ? $faq : HelpSearch::search($faq, $query),
            'open' => (string) $request->query('guia', ''),
        ];
    }
}
