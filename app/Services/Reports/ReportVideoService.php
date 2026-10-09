<?php

namespace App\Services\Reports;

use App\Enums\PlanFeature;
use App\Models\Company;
use App\Models\Report;
use App\Models\ReportVideo;
use App\Models\User;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Video de un reporte (Profesional y Empresa; uno por reporte).
 *
 * - Plan, cantidad, tamaño y formato se validan en el servidor al guardar
 *   (no alcanza con ocultar el campo).
 * - El formato se detecta por el contenido del archivo (no por la extensión).
 * - Con FFmpeg en el servidor, se comprime a MP4 H.264 (720p) en segundo plano
 *   (scheduler: media:process-videos), sin demorar la carga. Sin FFmpeg se
 *   guarda tal como llegó (dentro de los límites).
 */
class ReportVideoService
{
    public static function maxKb(): int
    {
        return (int) config('media.video_max_mb') * 1024;
    }

    /** @return array<string, array<int, string>> */
    public static function rules(string $field = 'video'): array
    {
        return [$field => ['nullable', 'file', 'max:'.static::maxKb()]];
    }

    public static function messages(string $field = 'video'): array
    {
        return [
            "{$field}.max" => 'El video puede pesar hasta '.config('media.video_max_mb').' MB.',
            "{$field}.file" => 'No se pudo recibir el video. Probá de nuevo.',
            "{$field}.uploaded" => 'No se pudo recibir el video (¿muy pesado?). Probá con uno más corto.',
        ];
    }

    public static function allowedFor(?Company $company): bool
    {
        return $company !== null && PlanGuard::for($company)->allows(PlanFeature::ReportVideos);
    }

    public static function ffmpegAvailable(): bool
    {
        $bin = (string) config('media.ffmpeg');

        return $bin !== '' && is_file($bin) && is_executable($bin);
    }

    /**
     * @throws ValidationException
     */
    public function store(Report $report, UploadedFile $file, ?User $actor = null): ReportVideo
    {
        if (ReportVideo::withoutGlobalScopes()->where('report_id', $report->id)->exists()) {
            throw ValidationException::withMessages(['video' => 'Este reporte ya tiene un video. Borralo para subir otro.']);
        }

        [$mime, $duration] = $this->check(Company::find($report->company_id), $file);

        $path = 'reports/'.$report->company_id.'/videos/'.Str::random(40).'.'.ReportVideo::MIMES[$mime];
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $video = new ReportVideo;
        $video->forceFill([
            'company_id' => $report->company_id,
            'report_id' => $report->id,
            'uploaded_by' => $actor?->id,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'duration' => $duration,
            'status' => static::ffmpegAvailable() ? ReportVideo::PENDING : ReportVideo::READY,
            'processing_note' => static::ffmpegAvailable() ? null : 'Se guardó sin comprimir (el servidor no tiene FFmpeg).',
        ])->save();

        return $video;
    }

    /**
     * Plan, tamaño, formato real y duración. Se puede llamar ANTES de crear
     * el reporte (así un video inválido no deja archivos de fotos sueltos).
     *
     * @return array{0: string, 1: ?int} [mime, duración]
     *
     * @throws ValidationException
     */
    public function check(?Company $company, UploadedFile $file): array
    {
        if (! static::allowedFor($company)) {
            throw ValidationException::withMessages(['video' => 'Los videos en reportes están incluidos en los planes Profesional y Empresa.']);
        }

        if (! $file->isValid() || $file->getSize() > static::maxKb() * 1024) {
            throw ValidationException::withMessages(['video' => 'El video puede pesar hasta '.config('media.video_max_mb').' MB.']);
        }

        $mime = $this->detectMime($file->getRealPath());

        if (! $mime) {
            throw ValidationException::withMessages(['video' => 'Formato de video no permitido. Usá MP4, MOV (iPhone) o WEBM.']);
        }

        $duration = $this->probeDuration($file->getRealPath());

        if ($duration !== null && $duration > (int) config('media.video_max_seconds')) {
            throw ValidationException::withMessages(['video' => 'El video puede durar hasta '.config('media.video_max_seconds').' segundos.']);
        }

        return [$mime, $duration];
    }

    public function delete(ReportVideo $video): void
    {
        $video->deleteFile();
        $video->delete();
    }

    /** MP4 / MOV (caja "ftyp") o WEBM (EBML), comprobado por contenido. */
    public function detectMime(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, 16) : '';
        $handle && fclose($handle);

        $finfo = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (substr($head, 4, 4) === 'ftyp') {
            return str_contains(substr($head, 8, 4), 'qt') || $finfo === 'video/quicktime' ? 'video/quicktime' : 'video/mp4';
        }

        if (str_starts_with($head, "\x1A\x45\xDF\xA3") && in_array($finfo, ['video/webm', 'video/x-matroska', 'application/octet-stream'], true)) {
            return 'video/webm';
        }

        return null;
    }

    /** Duración en segundos (solo si el servidor tiene ffprobe). */
    public function probeDuration(string $path): ?int
    {
        $bin = (string) config('media.ffprobe');

        if ($bin === '' || ! is_file($bin) || ! is_executable($bin)) {
            return null;
        }

        try {
            $process = new Process([$bin, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
            $process->setTimeout(20)->run();

            return $process->isSuccessful() ? (int) ceil((float) trim($process->getOutput())) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Comprime un video pendiente (lo corre el scheduler). Toma el video de a
     * uno (estado atómico) para no procesarlo dos veces. Si FFmpeg falla, se
     * conserva el original y queda una nota.
     */
    public function process(ReportVideo $video): void
    {
        $taken = ReportVideo::withoutGlobalScopes()->whereKey($video->id)->where('status', ReportVideo::PENDING)
            ->update(['status' => ReportVideo::PROCESSING, 'updated_at' => now()]);

        if (! $taken) {
            return;
        }

        $video->refresh();
        $source = $video->fullPath();

        if (! $source || ! static::ffmpegAvailable()) {
            $video->forceFill(['status' => ReportVideo::READY, 'processing_note' => 'Se guardó sin comprimir.', 'processed_at' => now()])->save();

            return;
        }

        $target = 'reports/'.$video->company_id.'/videos/'.Str::random(40).'.mp4';
        $targetFull = Storage::disk('local')->path($target);
        $height = (int) config('media.video_max_height');

        try {
            $process = new Process([
                (string) config('media.ffmpeg'), '-y', '-i', $source,
                '-vf', "scale=-2:'min({$height},ih)'",
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', (string) config('media.video_crf'), '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', $targetFull,
            ]);
            $process->setTimeout(600)->run();

            if (! $process->isSuccessful() || ! is_file($targetFull) || filesize($targetFull) === 0) {
                throw new \RuntimeException('ffmpeg falló');
            }

            // Solo se reemplaza si mejora: más liviano o el original no se ve en navegadores.
            if (filesize($targetFull) < $video->size || ! $video->isWebPlayable()) {
                $old = $video->path;
                $video->forceFill(['path' => $target, 'mime' => 'video/mp4', 'size' => (int) filesize($targetFull), 'processing_note' => null])->save();
                Storage::disk('local')->delete($old);
            } else {
                Storage::disk('local')->delete($target);
            }

            $video->forceFill(['status' => ReportVideo::READY, 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            Storage::disk('local')->delete($target);
            Log::warning('No se pudo comprimir un video de reporte', ['video_id' => $video->id, 'exception' => $e::class]);
            $video->forceFill(['status' => ReportVideo::READY, 'processing_note' => 'No se pudo comprimir; se conserva el original.', 'processed_at' => now()])->save();
        }
    }

    /** Pendientes (y los que quedaron colgados) — lo llama media:process-videos. */
    public function processPending(int $max = 2): int
    {
        ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::PROCESSING)->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => ReportVideo::PENDING]);

        $done = 0;
        ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::PENDING)->orderBy('id')->limit($max)->get()
            ->each(function (ReportVideo $video) use (&$done) {
                $this->process($video);
                $done++;
            });

        return $done;
    }
}
