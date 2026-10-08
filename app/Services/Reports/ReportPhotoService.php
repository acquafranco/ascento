<?php

namespace App\Services\Reports;

use App\Models\Report;
use App\Models\ReportPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Único lugar donde se guardan y borran fotos de reportes (app del técnico y
 * panel). Cada foto se decodifica y se vuelve a codificar como JPEG (no pasa
 * nada que no sea una imagen), se orienta sola, se achica a 2000 px y se
 * guarda en el disco privado con nombre aleatorio.
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
                Storage::disk('local')->delete($data['path']);
            }

            throw $e;
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

    /** @return array{path: string, width: int, height: int, size: int} */
    private function process(UploadedFile|string $file, int $companyId): array
    {
        $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $path = 'reports/'.$companyId.'/'.Str::random(40).'.jpg';
        $fullPath = Storage::disk('local')->path($path);

        try {
            if (! is_dir(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0755, true);
            }

            // Imagick si está (lee HEIC de iPhone); si no, GD.
            $manager = ImageManager::usingDriver(extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class);

            $image = $manager->decode(fopen($source, 'rb'))
                ->scaleDown(width: self::MAX_SIDE, height: self::MAX_SIDE);

            $image->encode(new JpegEncoder(quality: 85))->save($fullPath);

            return [
                'path' => $path,
                'width' => $image->width(),
                'height' => $image->height(),
                'size' => (int) filesize($fullPath),
            ];
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            logger()->warning('Foto de reporte inválida', ['message' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'photos' => 'No se pudo procesar una de las fotos. Probá con otra foto o formato.',
            ]);
        }
    }
}
