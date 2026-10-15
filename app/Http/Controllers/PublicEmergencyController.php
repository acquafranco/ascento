<?php

namespace App\Http\Controllers;

use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Services\Emergency\EmergencyRequestService;
use App\Services\PublicQr\PublicQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pedido de ayuda por posible atrapamiento desde el QR público (sin cuenta).
 *
 * Solo se recibe: el token del QR (en la URL), el comprobante firmado que
 * entregó la página y, opcionalmente, el ascensor. Empresa, edificio, cliente,
 * plan, interruptor y destinatarios se resuelven en el servidor. No usa la
 * sesión (funciona con cookies bloqueadas): el comprobante firmado reemplaza al
 * token CSRF y es la clave de idempotencia.
 *
 * Respuestas: HTML (formulario común) o JSON (envío desde la página). Nunca
 * dice «enviada» antes de que la solicitud esté registrada, y nunca afirma que
 * alguien la vio o la atendió.
 */
class PublicEmergencyController extends Controller
{
    public function store(Request $request, string $token, EmergencyRequestService $service): Response|JsonResponse
    {
        $page = strlen($token) === PublicQrService::TOKEN_LENGTH
            ? BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first()
            : null;

        // Sin QR válido: la misma respuesta que un QR inexistente (sin pistas).
        if (! $page) {
            return $this->respond($request, ['state' => 'unavailable'], null, 404);
        }

        $result = $service->submit($page, $request->input('k'), $request->input('ascensor'), $request->ip());
        $r = $result['request'];

        $state = match ($result['status']) {
            'created', 'duplicate', 'repeat' => $r && (int) $r->recipients_count === 0 && (int) $r->notify_rounds > 0 ? 'registered_unnotified' : 'registered',
            'invalid' => 'expired',
            default => 'not_registered', // unavailable / limited: genérico
        };

        $data = [
            'state' => $state,
            'repeat' => $result['status'] === 'repeat',
            'reference' => $r?->reference,
            'at' => $r?->created_at?->format('H:i'),
            'date' => $r?->created_at?->format('d/m/Y'),
        ];

        return $this->respond($request, $data, $page);
    }

    private function respond(Request $request, array $data, ?BuildingPublicPage $page, int $status = 200): Response|JsonResponse
    {
        $headers = [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];

        if ($request->expectsJson()) {
            return response()->json(['state' => $data['state'], 'html' => view('public-qr.partials.help-result', $this->viewData($data, $page))->render()], $status, $headers);
        }

        return response()->view('public-qr.help-result', $this->viewData($data, $page), $status)->withHeaders($headers);
    }

    private function viewData(array $data, ?BuildingPublicPage $page): array
    {
        $company = $page ? Company::find($page->company_id) : null;

        return $data + [
            'phone' => $page?->show_contact ? ($company?->phone ?: null) : null,
            'back' => $page ? $page->publicUrl() : null,
        ];
    }
}
