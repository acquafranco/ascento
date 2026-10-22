<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\ClientClaim;
use App\Models\Company;
use App\Services\Claims\ClientClaimService;
use App\Services\PublicQr\PublicQrService;
use App\Support\Countries;
use App\Support\PublicQr\QrReportNonce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * «Reportar un problema» desde el QR público (Ascento Conecta), sin cuenta.
 *
 * Tres pasos, con o sin JavaScript: 1) qué se observó, 2) resumen antes de
 * enviar (y foto opcional), 3) resultado REAL que decide el servidor.
 *
 * - Edificio, empresa y cliente salen del token del QR; el ascensor solo se
 *   acepta si es de ese edificio y se publica. Nada del navegador decide a
 *   quién pertenece el reporte.
 * - Sin sesión ni CSRF de cookies (funciona con cookies bloqueadas): lo
 *   reemplaza un comprobante firmado de la página, que además es la clave de
 *   idempotencia (un reintento no duplica) y exige unos segundos de uso.
 * - Campo trampa para robots y límites por IP (ruta), por edificio y por
 *   empresa (servicio).
 * - El mensaje final nunca dice que alguien lo vio, que un técnico va a ir
 *   ni que el problema se va a resolver.
 */
class PublicQrReportController extends Controller
{
    public const HONEYPOT = 'sitio_web';

    public function create(string $token): Response
    {
        $page = $this->page($token);
        if (! ClientClaimService::qrAvailableFor($page)) {
            return $this->view('public-qr.report.unavailable', ['back' => $page?->publicUrl()], 404);
        }

        return $this->view('public-qr.report.form', $this->formData($page, [], QrReportNonce::issue($page)));
    }

    /** Paso 2: el resumen (o volver a corregir). Todavía no se guarda nada. */
    public function review(Request $request, string $token): Response
    {
        $page = $this->page($token);
        if (! ClientClaimService::qrAvailableFor($page)) {
            return $this->view('public-qr.report.unavailable', ['back' => $page?->publicUrl()], 404);
        }

        $nonce = (string) $request->input('k');
        if (QrReportNonce::key($page, $nonce) === null) {
            return $this->view('public-qr.report.result', ['state' => 'expired', 'back' => route('public-qr.report', ['token' => $page->token])] + $this->safety($page) + $this->head($page), 419);
        }

        $validator = Validator::make($request->all(), $this->rules($page), $this->messages());
        if ($request->input('accion') === 'corregir' || $validator->fails()) {
            $editing = $request->input('accion') === 'corregir';

            // (array_merge: los errores reemplazan a la bolsa vacía de formData)
            return $this->view('public-qr.report.form', array_merge($this->formData($page, $request->only(['category', 'description', 'elevator_id']), $nonce),
                ['errors' => $editing ? new MessageBag : $validator->errors()]), $editing ? 200 : 422);
        }

        $data = $validator->validated();

        return $this->view('public-qr.report.review', $this->formData($page, $data, $nonce) + [
            'elevatorLabel' => ClientClaimService::qrElevators($page)->firstWhere('id', (int) ($data['elevator_id'] ?? 0))?->label,
        ]);
    }

    /** Paso 3: se registra (primero se guarda, después se avisa). */
    public function store(Request $request, string $token, ClientClaimService $claims): Response|JsonResponse
    {
        $page = $this->page($token);
        if (! ClientClaimService::qrAvailableFor($page)) {
            return $this->result($request, $page, ['state' => 'unavailable'], 404);
        }

        // Robot: el campo trampa vino lleno. Respuesta genérica, nada se guarda.
        if (filled($request->input(self::HONEYPOT))) {
            return $this->result($request, $page, ['state' => 'not_registered'], 422);
        }

        $key = QrReportNonce::key($page, (string) $request->input('k'), requireAge: true);
        if ($key === null) {
            return $this->result($request, $page, ['state' => 'expired'], 419);
        }

        $validator = Validator::make($request->all(), $this->rules($page) + [
            'foto' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ], $this->messages());
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['state' => 'invalid', 'errors' => $validator->errors()], 422, $this->headers());
            }

            return $this->view('public-qr.report.form', array_merge($this->formData($page, $request->only(['category', 'description', 'elevator_id']), (string) $request->input('k')), ['errors' => $validator->errors()]), 422);
        }

        $result = $claims->createFromQr($page, $validator->validated(), $request->file('foto'), $key, $request->ip());
        $claim = $result['claim'];

        return $this->result($request, $page, [
            'state' => match ($result['status']) {
                'created' => 'registered',
                'duplicate' => 'duplicate',
                'limited' => 'limited',
                default => 'unavailable',
            },
            'reference' => $claim?->reference(),
            'date' => $claim?->created_at?->format('d/m/Y'),
            'at' => $claim?->created_at?->format('H:i'),
        ], $result['status'] === 'limited' ? 429 : 200);
    }

    private function page(string $token): ?BuildingPublicPage
    {
        return strlen($token) === PublicQrService::TOKEN_LENGTH && ctype_alnum($token)
            ? BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first()
            : null;
    }

    private function rules(BuildingPublicPage $page): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(ClientClaim::QR_CATEGORIES))],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'elevator_id' => ['nullable', 'integer', Rule::in(ClientClaimService::qrElevators($page)->pluck('id')->all())],
        ];
    }

    private function messages(): array
    {
        return [
            'category.required' => 'Elegí qué notaste.',
            'category.in' => 'Elegí una de las opciones.',
            'description.required' => 'Contá brevemente qué pasa.',
            'description.min' => 'Contá brevemente qué pasa (al menos unas palabras).',
            'description.max' => 'Usá hasta 1000 caracteres.',
            'elevator_id.in' => 'Elegí uno de los ascensores de la lista.',
            'foto.image' => 'La foto tiene que ser una imagen JPG, PNG o WebP.',
            'foto.mimes' => 'La foto tiene que ser una imagen JPG, PNG o WebP.',
            'foto.mimetypes' => 'La foto tiene que ser una imagen JPG, PNG o WebP.',
            'foto.max' => 'La foto puede pesar hasta 5 MB.',
            'foto.uploaded' => 'No se pudo subir la foto. Probá con una más liviana o enviá el reporte sin foto.',
        ];
    }

    /** Empresa y edificio que identifica el QR (para el encabezado). */
    private function head(?BuildingPublicPage $page): array
    {
        $company = $page ? Company::find($page->company_id) : null;
        $building = $page ? Building::withoutGlobalScopes()->where('company_id', $page->company_id)->find($page->building_id) : null;

        return [
            'companyName' => $company?->name,
            'buildingName' => $building?->name,
            'buildingAddress' => trim(collect([$building?->address, $building?->locality])->filter()->implode(', ')),
            'country' => Countries::normalize($building?->country ?: $company?->country),
        ];
    }

    private function formData(BuildingPublicPage $page, array $old, string $nonce): array
    {
        return $this->head($page) + [
            'page' => $page,
            'old' => $old,
            'nonce' => $nonce,
            'elevators' => ClientClaimService::qrElevators($page),
            'categories' => ClientClaim::QR_CATEGORIES,
            'errors' => new MessageBag,
        ] + $this->safety($page);
    }

    /** Teléfono de la empresa solo si el QR lo publica. */
    private function safety(?BuildingPublicPage $page): array
    {
        $company = $page ? Company::find($page->company_id) : null;

        return ['phone' => $page?->show_contact ? ($company?->phone ?: null) : null];
    }

    private function result(Request $request, ?BuildingPublicPage $page, array $data, int $status): Response|JsonResponse
    {
        $data += ['back' => $page?->publicUrl(), 'reference' => null, 'date' => null, 'at' => null] + $this->safety($page) + $this->head($page);

        if ($request->expectsJson()) {
            return response()->json(['state' => $data['state'], 'html' => view('public-qr.report.partials.result', $data)->render()], $status, $this->headers());
        }

        return $this->view('public-qr.report.result', $data, $status);
    }

    private function view(string $name, array $data, int $status = 200): Response
    {
        return response()->view($name, $data, $status)->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
