<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Services\Emergency\EmergencyRequestService;
use App\Services\PublicQr\PublicBuildingInfo;
use App\Services\PublicQr\PublicQrService;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * QR público de un edificio (Etapa 7).
 *
 * show(): página de SOLO LECTURA, sin cuenta. El token se busca en cada
 * pedido: si se desactivó o regeneró, el enlace anterior responde 404 (lo
 * mismo que un token inexistente, sin pistas). No tiene formularios ni
 * enlaces al portal o al panel, no se indexa y no se guarda en caché.
 *
 * download(): la imagen del QR, para quien gestiona o ve el QR del edificio
 * en el panel (área «QR y datos públicos»).
 */
class PublicQrController extends Controller
{
    public function show(string $token, PublicBuildingInfo $info): Response
    {
        $page = strlen($token) === PublicQrService::TOKEN_LENGTH
            ? BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first()
            : null;

        $company = $page ? Company::find($page->company_id) : null;
        // Sin scopes (no hay sesión), pero un edificio desactivado no se publica.
        $building = $page ? Building::withoutGlobalScopes()->where('company_id', $page->company_id)->whereNull('deleted_at')->find($page->building_id) : null;

        // Sin la función en el plan (si el SuperAdmin la quita), tampoco: el token se conserva y vuelve con el plan.
        if (! $page || ! $company || ! $company->hasActiveAccess() || ! $building || ! PlanGuard::for($company)->allows(PlanFeature::PublicQr)) {
            return $this->headers(response()->view('public-qr.unavailable', [], 404));
        }

        // Pedido de ayuda: solo si la empresa lo habilitó y su plan lo incluye (se vuelve a verificar al enviarlo).
        $emergency = app(EmergencyRequestService::class);
        $help = $emergency->availableFor($page) ? [
            'action' => route('public-qr.help', ['token' => $page->token]),
            'nonce' => $emergency->issueNonce($page),
            'elevators' => $emergency->elevatorChoices($page),
        ] : null;

        return $this->headers(response()->view('public-qr.show', ['info' => $info->for($page), 'preview' => false, 'help' => $help]));
    }

    public function download(Request $request, Building $building, string $format, PublicQrService $service): Response
    {
        abort_unless(Access::can($request->user(), PermissionArea::PublicPages) && PlanGuard::currentAllows(PlanFeature::PublicQr), 404);

        $page = $service->pageOf($building);
        abort_unless($page && $page->is_active, 404);

        $png = $format === 'png';

        return response($service->image($page, $png ? 'png' : 'svg'), 200, [
            'Content-Type' => $png ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="qr-'.Str::slug($building->name ?: 'edificio').'.'.($png ? 'png' : 'svg').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Hoja imprimible del QR: código grande con su margen libre, empresa (y su logo),
     * instrucción y Ascento como plataforma. Todo embebido (sin imágenes remotas).
     */
    public function sheet(Request $request, Building $building, string $size, PublicQrService $service): Response
    {
        abort_unless(Access::can($request->user(), PermissionArea::PublicPages) && PlanGuard::currentAllows(PlanFeature::PublicQr), 404);

        $page = $service->pageOf($building);
        abort_unless($page && $page->is_active, 404);

        $company = Company::find($building->company_id);
        $embed = function (string $disk, ?string $path, array $types): ?string {
            if (! $path || ! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $mime = Storage::disk($disk)->mimeType($path);

            return in_array($mime, $types, true) ? 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($path)) : null;
        };
        $ascento = public_path('images/brand/logo-256.png');

        return response()->view('public-qr.sheet', [
            'size' => $size,
            'qr' => $service->image($page, 'svg'),
            'company' => $company,
            'companyLogo' => $embed('public', $company?->logo, ['image/png', 'image/jpeg', 'image/webp']),
            'ascentoLogo' => is_file($ascento) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($ascento)) : null,
            'building' => $building,
            'help' => app(EmergencyRequestService::class)->availableFor($page),
            'url' => $page->publicUrl(),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function headers(Response $response): Response
    {
        return $response->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
