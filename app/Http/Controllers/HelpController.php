<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpGuides;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ayuda de la app del técnico y del portal del cliente (Etapa 9.3): las
 * guías de HelpGuides para ese perfil, según el plan de su empresa. El
 * acceso lo dan los middleware de cada grupo de rutas (técnico de la
 * empresa del enlace; usuario del portal con acceso vigente).
 */
class HelpController extends Controller
{
    public function technician(Request $request): View
    {
        abort_unless($request->user()?->role === 'technician', 403);

        return view('help.technician', [
            'grouped' => HelpGuides::grouped(HelpGuides::for($request->user(), HelpGuides::TECHNICIAN)),
            'open' => (string) $request->query('guia', ''),
        ]);
    }

    public function portal(Request $request): View
    {
        return view('portal.help', [
            'grouped' => HelpGuides::grouped(HelpGuides::for($request->user(), HelpGuides::PORTAL)),
            'open' => (string) $request->query('guia', ''),
        ]);
    }
}
