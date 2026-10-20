<?php

namespace App\Services\Elevators;

use App\Enums\PermissionArea;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda documentos del legajo (planos, manuales, certificados, fotos) en el
 * disco privado, con nombre aleatorio y la extensión que corresponde al tipo
 * REAL del archivo (no al nombre que trae).
 */
class ElevatorDocumentService
{
    public const MAX_KB = 20480;

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(Elevator $elevator, UploadedFile $file, string $type, string $title, ?string $expiresAt, ?User $user): ElevatorDocument
    {
        // Con sesión, solo en equipos de la empresa actual.
        $companyId = CompanyContext::currentId();
        abort_if($companyId !== null && $companyId !== (int) $elevator->company_id, 404);

        // Tipo por CONTENIDO (magic bytes), nunca por la extensión del nombre.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['file' => 'Solo PDF o imágenes (JPG, PNG, WEBP).']);
        }

        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['file' => 'El archivo puede pesar hasta 20 MB.']);
        }

        if (! array_key_exists($type, ElevatorDocument::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Tipo de documento no válido.']);
        }

        $path = 'elevators/'.$elevator->company_id.'/'.Str::random(40).'.'.self::MIMES[$mime];
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        try {
            $document = new ElevatorDocument([
                'elevator_id' => $elevator->id,
                'type' => $type,
                'title' => Str::limit(trim($title) ?: $file->getClientOriginalName(), 250, ''),
                'expires_at' => $expiresAt ?: null,
            ]);
            $document->forceFill([
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
                'mime' => $mime,
                'size' => $file->getSize(),
                'uploaded_by' => $user?->id,
            ])->save();

            return $document;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Publicar (o retirar) un documento en el pasaporte público del QR: una
     * acción explícita, de quien gestiona documentos Y los datos públicos, en
     * su empresa. Queda quién y cuándo. El documento se ve solo si el QR del
     * edificio tiene encendido el bloque «Documentación».
     */
    public function publishOnQr(ElevatorDocument $document, User $actor, bool $public): ElevatorDocument
    {
        abort_unless(
            Access::can($actor, PermissionArea::Documents, PermissionArea::MANAGE)
            && Access::can($actor, PermissionArea::PublicPages, PermissionArea::MANAGE),
            403,
        );
        abort_unless((int) $document->company_id === (int) CompanyContext::currentId(), 404);

        $document->forceFill([
            'public_on_qr' => $public,
            'published_on_qr_at' => $public ? now() : null,
            'published_on_qr_by' => $public ? $actor->id : null,
        ])->save();

        return $document;
    }
}
