<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Services\Claims\ClientClaimService;
use App\Services\PublicQr\PublicBuildingInfo;
use App\Services\PublicQr\PublicQrService;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * QR público de un edificio (Etapa 7).
 *
 * show(): pasaporte del ascensor, sin cuenta (Ascento Conecta). El token se
 * busca en cada pedido: si se desactivó o regeneró, el enlace anterior
 * responde 404 (lo mismo que un token inexistente, sin pistas). Su única
 * acción es «Reportar un problema» (PublicQrReportController). No enlaza al
 * portal ni al panel, no se indexa y no se guarda en caché.
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

        // «Reportar un problema»: si el plan lo incluye y la empresa no lo apagó (se vuelve a verificar al enviar).
        $report = ClientClaimService::qrAvailableFor($page) ? route('public-qr.report', ['token' => $page->token]) : null;

        return $this->headers(response()->view('public-qr.show', ['info' => $info->for($page), 'preview' => false, 'report' => $report]));
    }

    /**
     * Documento técnico PUBLICADO en el QR (pasaporte del ascensor). Solo si
     * el QR está activo, el plan lo incluye, el bloque «Documentación» está
     * encendido, el documento es de un ascensor de ESTE edificio y empresa y
     * la empresa lo publicó. Cualquier otro caso: 404 (cambiar el número en la
     * dirección no da acceso a nada).
     */
    public function document(string $token, int $document): StreamedResponse|Response
    {
        $page = strlen($token) === PublicQrService::TOKEN_LENGTH
            ? BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first()
            : null;
        $company = $page ? Company::find($page->company_id) : null;

        abort_unless($page && $page->show_documents && $company && $company->hasActiveAccess() && PlanGuard::for($company)->allows(PlanFeature::PublicQr), 404);

        $file = ElevatorDocument::withoutGlobalScopes()->where('company_id', $page->company_id)->where('public_on_qr', true)
            ->whereIn('elevator_id', Elevator::withoutGlobalScopes()->where('company_id', $page->company_id)->where('building_id', $page->building_id)->select('id'))
            ->find($document);

        abort_unless($file && $file->hasSafePath() && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->original_name ?: basename($file->path), [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
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
            'report' => ClientClaimService::qrAvailableFor($page),
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
