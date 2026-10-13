<?php

namespace App\Services\PublicQr;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Elevator;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * QR público de un edificio (Etapa 7). Generar, desactivar, regenerar, qué
 * se publica y el estado informado de cada ascensor: «QR y datos públicos:
 * gestionar» (o el dueño), siempre de la empresa actual. El token es
 * aleatorio (48 caracteres); regenerar o desactivar deja sin efecto el
 * anterior al instante (la página pública lo busca en cada pedido).
 */
class PublicQrService
{
    public const TOKEN_LENGTH = 48;

    private function authorizeManage(?User $actor): void
    {
        abort_unless(Access::can($actor, PermissionArea::PublicPages, PermissionArea::MANAGE), 403);
    }

    private function ensureCompany(int $companyId): void
    {
        $current = CompanyContext::currentId();
        abort_if($current === null || $current !== $companyId, 404);
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (BuildingPublicPage::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }

    public function pageOf(Building $building): ?BuildingPublicPage
    {
        return BuildingPublicPage::withoutGlobalScopes()->where('company_id', $building->company_id)->where('building_id', $building->id)->first();
    }

    /**
     * Genera el QR (o uno nuevo, si ya había): token nuevo y activo. El
     * enlace anterior, si existía, deja de funcionar.
     */
    public function generate(User $actor, Building $building): BuildingPublicPage
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $building->company_id);
        abort_if($building->trashed(), 404);

        $page = $this->pageOf($building) ?? new BuildingPublicPage;
        $page->forceFill([
            'company_id' => $building->company_id,
            'building_id' => $building->id,
            'token' => $this->newToken(),
            'is_active' => true,
            'generated_by' => $actor->id,
            'generated_at' => now(),
            'deactivated_at' => null,
        ])->save();

        return $page;
    }

    public function deactivate(User $actor, BuildingPublicPage $page): BuildingPublicPage
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $page->company_id);

        // Además de apagarlo, se cambia el token: reactivarlo nunca revive el enlace viejo.
        $page->forceFill(['is_active' => false, 'deactivated_at' => now(), 'token' => $this->newToken()])->save();

        return $page;
    }

    /** Qué se publica: solo los bloques conocidos (cualquier otra clave se ignora). */
    public function updateSections(User $actor, BuildingPublicPage $page, array $sections): BuildingPublicPage
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $page->company_id);

        $values = [];
        foreach (array_keys(BuildingPublicPage::SECTIONS) as $key) {
            if (array_key_exists($key, $sections)) {
                $values[$key] = (bool) $sections[$key];
            }
        }

        $page->forceFill($values)->save();

        return $page;
    }

    /** Estado informativo de un ascensor en el QR (null: no se informa). */
    public function setElevatorStatus(User $actor, Elevator $elevator, ?string $status): Elevator
    {
        $this->authorizeManage($actor);
        $this->ensureCompany((int) $elevator->company_id);

        if ($status !== null && $status !== '' && ! array_key_exists($status, Elevator::PUBLIC_STATUSES)) {
            throw ValidationException::withMessages(['public_status' => 'Estado no válido.']);
        }

        $status = $status ?: null;
        if ($status !== $elevator->public_status) {
            $elevator->forceFill(['public_status' => $status, 'public_status_updated_at' => $status ? now() : null])->save();
        }

        return $elevator;
    }

    /** Imagen del QR: SVG (para imprimir) o PNG. Solo codifica el enlace público. */
    public function image(BuildingPublicPage $page, string $format = 'svg'): string
    {
        $options = new QROptions([
            'outputType' => $format === 'png' ? QROutputInterface::GDIMAGE_PNG : QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'scale' => 12,
            'quietzoneSize' => 4,
            'eccLevel' => EccLevel::M,
        ]);

        return (new QRCode($options))->render($page->publicUrl());
    }
}
