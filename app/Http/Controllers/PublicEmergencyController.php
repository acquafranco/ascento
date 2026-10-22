<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Services\PublicQr\PublicQrService;
use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pedido de ayuda por atrapamiento desde el QR: RETIRADO (Ascento Conecta).
 *
 * La página pública ya no lo ofrece y esta dirección no registra nada ni
 * avisa a nadie. Sigue existiendo solo para responder con claridad a una
 * página vieja abierta en un teléfono (o a un envío repetido): la opción ya no
 * existe, qué hacer ante una urgencia y dónde reportar un problema. Los pedidos
 * registrados antes se conservan como historial (panel y portal).
 */
class PublicEmergencyController extends Controller
{
    public function store(Request $request, string $token): Response|JsonResponse
    {
        $page = strlen($token) === PublicQrService::TOKEN_LENGTH
            ? BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first()
            : null;

        $headers = [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
        $building = $page ? Building::withoutGlobalScopes()->with('company')->find($page->building_id) : null;
        $data = ['back' => $page?->publicUrl(), 'country' => Countries::normalize($building?->country ?: $building?->company?->country)];

        if ($request->expectsJson()) {
            return response()->json(['state' => 'discontinued', 'html' => view('public-qr.partials.help-result', $data)->render()], 410, $headers);
        }

        return response()->view('public-qr.help-result', $data, 410)->withHeaders($headers);
    }
}
