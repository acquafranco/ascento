<?php

namespace App\Services\Technicians;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Media\PhotoProcessor;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Encoders\JpegEncoder;

/**
 * Foto de perfil de un técnico (Etapa 8.2). La cargan, reemplazan o quitan
 * el propio técnico o el dueño de su empresa (el SuperAdmin, dentro de la
 * empresa elegida). JPG, PNG o WebP de hasta 2 MB: se decodifica y se
 * vuelve a codificar como JPEG cuadrado de 400 px, sin metadatos, en el
 * disco privado con nombre aleatorio. Se sirve solo por una ruta con
 * permiso (TechnicianPhotoController); nunca desde una carpeta pública.
 */
class TechnicianPhotoService
{
    public const MAX_KB = 2048;

    public const SIDE = 400;

    public const MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    public const DIRECTORY = 'avatars';

    public function canChange(?User $actor, User $technician): bool
    {
        if ($actor === null || $technician->role !== 'technician' || $technician->company_id === null) {
            return false;
        }

        if ($actor->is($technician)) {
            return true;
        }

        // Los técnicos los gestiona el dueño (un administrativo con «Técnicos» solo consulta).
        return ($actor->isSuperAdmin() || Access::isOwner($actor))
            && CompanyContext::currentId() === (int) $technician->company_id;
    }

    public function replace(User $actor, User $technician, UploadedFile $file): User
    {
        abort_unless($this->canChange($actor, $technician), 403);

        Validator::make(['photo' => $file], [
            'photo' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:'.implode(',', self::MIMES), 'mimetypes:image/jpeg,image/png,image/webp'],
        ], [
            'photo.max' => 'La foto puede pesar hasta 2 MB.',
            'photo.mimes' => 'La foto tiene que ser JPG, PNG o WebP.',
            'photo.mimetypes' => 'La foto tiene que ser JPG, PNG o WebP.',
        ])->validate();

        $path = self::DIRECTORY.'/'.$technician->company_id.'/'.Str::random(40).'.jpg';
        $disk = Storage::disk('local');

        try {
            $info = @getimagesize($file->getRealPath());
            if (! $info || (int) $info[0] * (int) $info[1] > (int) config('media.photo_max_pixels', 50_000_000)) {
                throw new \RuntimeException('No es una imagen válida');
            }

            if (! is_dir(dirname($disk->path($path)))) {
                mkdir(dirname($disk->path($path)), 0755, true);
            }

            PhotoProcessor::manager()->decode(fopen($file->getRealPath(), 'rb'))
                ->cover(self::SIDE, self::SIDE)
                ->encode(new JpegEncoder(quality: 82))
                ->save($disk->path($path));
        } catch (\Throwable $e) {
            $disk->delete($path);
            logger()->warning('Foto de perfil inválida', ['message' => $e->getMessage()]);

            throw ValidationException::withMessages(['photo' => 'No se pudo procesar la foto. Probá con otra imagen.']);
        }

        // Cambio bajo bloqueo: dos reemplazos a la vez no dejan archivos
        // huérfanos (cada uno borra la foto que efectivamente reemplazó).
        try {
            $previous = DB::transaction(function () use ($technician, $path) {
                $locked = User::query()->whereKey($technician->id)->lockForUpdate()->first(['id', 'avatar']);
                $previous = $locked?->avatar;
                $technician->forceFill(['avatar' => $path])->saveQuietly();

                return $previous;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
        $this->deleteFile($previous);

        ActivityLogger::record('user.photo_updated', $technician, []);

        return $technician;
    }

    public function remove(User $actor, User $technician): User
    {
        abort_unless($this->canChange($actor, $technician), 403);

        $previous = DB::transaction(function () use ($technician) {
            $previous = User::query()->whereKey($technician->id)->lockForUpdate()->value('avatar');
            $technician->forceFill(['avatar' => null])->saveQuietly();

            return $previous;
        });

        if ($previous) {
            $this->deleteFile($previous);
            ActivityLogger::record('user.photo_removed', $technician, []);
        }

        return $technician;
    }

    /** Solo archivos de la carpeta de fotos de perfil (nunca otra ruta). */
    public static function hasSafePath(?string $path): bool
    {
        return is_string($path) && preg_match('#^'.self::DIRECTORY.'/\d+/[A-Za-z0-9]{40}\.jpg$#', $path) === 1;
    }

    private function deleteFile(?string $path): void
    {
        if (self::hasSafePath($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
