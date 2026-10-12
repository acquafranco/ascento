<?php

namespace App\Services\Reports;

use App\Models\Report;
use App\Models\ReportPhoto;
use App\Services\Media\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Único lugar donde se guardan y borran fotos de reportes (app del técnico y
 * panel). El procesamiento de cada imagen (re-codificar a JPEG, orientar,
 * quitar EXIF, achicar, miniatura, disco privado) es PhotoProcessor, el
 * mismo que usan las fotos de visitas.
 */
class ReportPhotoService
{
    public const MAX_PHOTOS = 6;

    public const MAX_KB = 10240;

    public const MAX_SIDE = 2000;

    /** Reglas de validación para un request con `photos[]`. */
    public static function rules(string $field = 'photos'): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            "{$field}.*" => ['file', 'max:'.self::MAX_KB, 'mimes:jpg,jpeg,png,webp,heic,heif'],
        ];
    }

    public static function messages(string $field = 'photos'): array
    {
        return [
            "{$field}.max" => 'Podés adjuntar hasta '.self::MAX_PHOTOS.' fotos por reporte.',
            "{$field}.*.mimes" => 'Formato de imagen no permitido. Usá JPG, PNG, WEBP o HEIC.',
            "{$field}.*.max" => 'Cada foto puede pesar hasta 10 MB.',
            "{$field}.*.file" => 'No se pudo recibir una de las fotos. Probá de nuevo.',
            "{$field}.*.uploaded" => 'No se pudo recibir una de las fotos (¿muy pesada?). Probá de nuevo.',
        ];
    }

    /**
     * Agrega fotos a un reporte. Todo o nada: si una falla, se borran los
     * archivos ya escritos y no se crea ninguna.
     *
     * @param  array<int, UploadedFile|string>  $files  archivos subidos o paths absolutos
     * @return array<int, ReportPhoto>
     */
    public function add(Report $report, array $files): array
    {
        $files = array_values(array_filter($files));

        if ($files === []) {
            return [];
        }

        $existing = $report->photos()->count();

        if ($existing + count($files) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'Un reporte puede tener hasta '.self::MAX_PHOTOS.' fotos (ya tiene '.$existing.').',
            ]);
        }

        $written = [];

        try {
            foreach ($files as $file) {
                $written[] = $this->process($file, (int) $report->company_id);
            }

            return DB::transaction(function () use ($report, $written, $existing) {
                $photos = [];

                foreach ($written as $i => $data) {
                    $photos[] = $report->photos()->create([...$data, 'position' => $existing + $i]);
                }

                return $photos;
            });
        } catch (\Throwable $e) {
            foreach ($written as $data) {
                Storage::disk('local')->delete(array_filter([$data['path'], $data['thumb_path'] ?? null]));
            }

            throw $e;
        }
    }

    /** Ver PhotoProcessor (mismo procesamiento para reportes y visitas). */
    public static function manager(): ImageManager
    {
        return PhotoProcessor::manager();
    }

    public static function thumbPathFor(string $path): string
    {
        return PhotoProcessor::thumbPathFor($path);
    }

    /**
     * Miniatura de una foto. Las fotos anteriores a las miniaturas la generan
     * una sola vez, la primera vez que se pide (no se reprocesa todo).
     */
    public function thumbnail(ReportPhoto $photo): ?string
    {
        if ($photo->thumb_path && Storage::disk('local')->exists($photo->thumb_path)) {
            return $photo->thumb_path;
        }

        $disk = $photo->disk();

        if (! $disk) {
            return null;
        }

        $thumb = static::thumbPathFor(preg_replace('/\.[a-z0-9]+$/i', '.jpg', $photo->path));

        try {
            Storage::disk('local')->makeDirectory(dirname($thumb));
            static::manager()->decode((string) Storage::disk($disk)->get($photo->path))
                ->scaleDown(width: (int) config('media.thumb_side', 480), height: (int) config('media.thumb_side', 480))
                ->encode(new JpegEncoder(quality: 75))->save(Storage::disk('local')->path($thumb));
            $photo->forceFill(['thumb_path' => $thumb])->saveQuietly();

            return $thumb;
        } catch (\Throwable) {
            return null; // se sirve la foto completa
        }
    }

    /** Borra una foto (y su archivo) y reordena las que quedan. */
    public function delete(ReportPhoto $photo): void
    {
        DB::transaction(function () use ($photo) {
            $reportId = $photo->report_id;
            $photo->delete();

            ReportPhoto::withoutGlobalScopes()->where('report_id', $reportId)->orderBy('position')->orderBy('id')->get()
                ->each(fn (ReportPhoto $p, int $i) => $p->position !== $i ? $p->forceFill(['position' => $i])->saveQuietly() : null);
        });
    }

    /** @return array{path: string, thumb_path: string, width: ?int, height: ?int, size: int} */
    private function process(UploadedFile|string $file, int $companyId): array
    {
        return app(PhotoProcessor::class)->store($file, 'reports/'.$companyId, 'photos', 'Foto de reporte inválida');
    }
}
