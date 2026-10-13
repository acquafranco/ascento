<?php

namespace App\Services\Reports;

use App\Enums\PlanFeature;
use App\Models\Company;
use App\Models\Report;
use App\Models\ReportVideo;
use App\Models\User;
use App\Support\Diagnostics\ServerLimits;
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
 * - La duración se valida SIEMPRE en el servidor: con ffprobe si está; si no,
 *   leyendo el encabezado del contenedor (MP4/MOV: caja mvhd; WEBM: Duration).
 *   Si no se puede leer, el video se rechaza (no se confía en el navegador).
 * - Con FFmpeg en el servidor, se comprime a MP4 H.264 (720p, techo de
 *   bitrate) en segundo plano (scheduler: media:process-videos), sin demorar
 *   la carga. Se escribe en un temporal y se renombra; el original se borra.
 *   Si FFmpeg no puede leerlo (dañado), el video queda "falló" y se borra.
 *   Al comprimir se mide la duración REAL (lo que FFmpeg decodifica, no el
 *   encabezado): si un encabezado manipulado declaraba menos, se rechaza.
 *   Sin FFmpeg se guarda tal como llegó (dentro de los límites; el tamaño
 *   máximo acota el daño de un encabezado falso).
 */
class ReportVideoService
{
    public static function maxKb(): int
    {
        return static::effectiveMaxMb() * 1024;
    }

    /**
     * Tamaño máximo real en MB: el configurado (MEDIA_VIDEO_MAX_MB) o menos,
     * si el PHP que atiende la web acepta menos (upload_max_filesize y
     * post_max_size, con 5 MB de margen para las fotos del mismo envío). Así
     * el formulario avisa ANTES de subir, en vez de fallar al terminar.
     * En consola (comandos, pruebas) se usa el configurado: la CLI no es la web.
     */
    public static function effectiveMaxMb(): int
    {
        $configured = (int) config('media.video_max_mb');
        if (app()->runningInConsole()) {
            return $configured;
        }

        $upload = ServerLimits::toMb((string) ini_get('upload_max_filesize')) ?? INF;
        $post = ServerLimits::toMb((string) ini_get('post_max_size')) ?? INF;

        return max(1, (int) floor(min($configured, $upload, $post - 5)));
    }

    /** @return array<string, array<int, string>> */
    public static function rules(string $field = 'video'): array
    {
        return [$field => ['nullable', 'file', 'max:'.static::maxKb()]];
    }

    public static function messages(string $field = 'video'): array
    {
        return [
            "{$field}.max" => 'El video puede pesar hasta '.static::effectiveMaxMb().' MB.',
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
        // Un video que falló no cuenta: se reemplaza.
        ReportVideo::withoutGlobalScopes()->where('report_id', $report->id)->where('status', ReportVideo::FAILED)->get()
            ->each(fn (ReportVideo $failed) => $this->delete($failed));

        if (ReportVideo::withoutGlobalScopes()->where('report_id', $report->id)->exists()) {
            throw ValidationException::withMessages(['video' => 'Este reporte ya tiene un video. Borralo para subir otro.']);
        }

        [$mime, $duration] = $this->check(Company::find($report->company_id), $file);

        $path = 'reports/'.$report->company_id.'/videos/'.Str::random(40).'.'.ReportVideo::MIMES[$mime];
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $video = new ReportVideo;
        try {
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
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path); // sin archivos huérfanos

            throw $e;
        }

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
            throw ValidationException::withMessages(['video' => 'El video puede pesar hasta '.static::effectiveMaxMb().' MB.']);
        }

        $mime = $this->detectMime($file->getRealPath());

        if (! $mime) {
            throw ValidationException::withMessages(['video' => 'Formato de video no permitido. Usá MP4, MOV (iPhone) o WEBM.']);
        }

        $duration = $this->duration($file->getRealPath(), $mime);

        if ($duration === null) {
            throw ValidationException::withMessages(['video' => 'No pudimos leer la duración del video (puede estar dañado). Grabalo de nuevo con la cámara del teléfono.']);
        }

        if ($duration > (int) config('media.video_max_seconds')) {
            throw ValidationException::withMessages(['video' => 'El video dura '.$duration.' segundos y el máximo es '.config('media.video_max_seconds').'. Grabá uno más corto.']);
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

    /** Duración en segundos: ffprobe si está; si no, el encabezado del contenedor. */
    public function duration(string $path, string $mime): ?int
    {
        if (static::ffprobeAvailable()) {
            return $this->probeDuration($path);
        }

        $seconds = $mime === 'video/webm' ? $this->webmDuration($path) : $this->mp4Duration($path);

        return $seconds === null ? null : (int) ceil($seconds);
    }

    public static function ffprobeAvailable(): bool
    {
        $bin = (string) config('media.ffprobe');

        return $bin !== '' && is_file($bin) && is_executable($bin);
    }

    /** MP4 / MOV: duración y escala de tiempo de la caja moov → mvhd. */
    public function mp4Duration(string $path): ?float
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return null;
        }

        try {
            $end = (int) filesize($path);
            $moov = $this->findBox($handle, 0, $end, 'moov');
            $mvhd = $moov ? $this->findBox($handle, $moov[0], $moov[1], 'mvhd') : null;
            if (! $mvhd) {
                return null;
            }

            fseek($handle, $mvhd[0]);
            $version = ord((string) fread($handle, 4));
            if ($version === 1) {
                fseek($handle, 16, SEEK_CUR);
                $scale = unpack('N', (string) fread($handle, 4))[1] ?? 0;
                $parts = unpack('N2', (string) fread($handle, 8));
                $duration = ($parts[1] ?? 0) * 4294967296 + ($parts[2] ?? 0);
            } else {
                fseek($handle, 8, SEEK_CUR);
                [$scale, $duration] = array_values(unpack('N2', (string) fread($handle, 8)) ?: [0, 0]);
            }

            return $scale > 0 && $duration > 0 ? $duration / $scale : null;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{0: int, 1: int}|null [inicio del contenido, fin] de la primera caja $type. */
    private function findBox($handle, int $from, int $to, string $type): ?array
    {
        $offset = $from;
        for ($i = 0; $i < 10000 && $offset + 8 <= $to; $i++) {
            fseek($handle, $offset);
            $header = (string) fread($handle, 8);
            if (strlen($header) < 8) {
                return null;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $name = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $parts = unpack('N2', (string) fread($handle, 8));
                $size = $parts[1] * 4294967296 + $parts[2];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $to - $offset;
            }
            if ($size < $headerSize) {
                return null;
            }
            if ($name === $type) {
                return [$offset + $headerSize, min($to, $offset + $size)];
            }
            $offset += $size;
        }

        return null;
    }

    /** WEBM: Segment → Info → Duration × TimecodeScale (sin Duration → null). */
    public function webmDuration(string $path): ?float
    {
        $data = (string) @file_get_contents($path, false, null, 0, 1024 * 1024);
        $pos = 0;
        $len = strlen($data);
        $scale = 1000000;
        $duration = null;
        $containers = ["\x18\x53\x80\x67", "\x15\x49\xA9\x66"]; // Segment, Info

        for ($i = 0; $i < 5000 && $pos < $len; $i++) {
            $id = $this->ebmlId($data, $pos);
            $size = $id === null ? null : $this->ebmlSize($data, $pos);
            if ($id === null || $size === null) {
                break;
            }
            if (in_array($id, $containers, true)) {
                continue; // se entra al contenido
            }
            if ($id === "\x1F\x43\xB6\x75") {
                break; // Cluster: ya pasó la cabecera
            }
            $value = substr($data, $pos, (int) $size);
            if ($id === "\x2A\xD7\xB1") {
                $scale = (int) hexdec(bin2hex($value)) ?: 1000000;
            } elseif ($id === "\x44\x89") {
                $duration = match (strlen($value)) {
                    4 => unpack('G', $value)[1],
                    8 => unpack('E', $value)[1],
                    default => null,
                };
            }
            $pos += (int) $size;
        }

        return $duration && $duration > 0 ? $duration * $scale / 1e9 : null;
    }

    private function ebmlId(string $data, int &$pos): ?string
    {
        $first = ord($data[$pos] ?? ' ');
        $length = match (true) {
            $first >= 0x80 => 1, $first >= 0x40 => 2, $first >= 0x20 => 3, $first >= 0x10 => 4, default => 0,
        };
        if ($length === 0) {
            return null;
        }
        $id = substr($data, $pos, $length);
        $pos += $length;

        return $id;
    }

    /** Tamaño EBML; los de tamaño "desconocido" (todo unos) se tratan como contenedor. */
    private function ebmlSize(string $data, int &$pos): ?int
    {
        $first = ord($data[$pos] ?? ' ');
        for ($length = 1; $length <= 8; $length++) {
            if ($first & (0x80 >> ($length - 1))) {
                break;
            }
        }
        if ($length > 8) {
            return null;
        }
        $value = $first & ((0xFF >> $length));
        $allOnes = $value === (0xFF >> $length);
        for ($i = 1; $i < $length; $i++) {
            $byte = ord($data[$pos + $i] ?? ' ');
            $allOnes = $allOnes && $byte === 0xFF;
            $value = ($value << 8) | $byte;
        }
        $pos += $length;

        return $allOnes ? 0 : $value;
    }

    /** Duración en segundos con ffprobe (null si no lo puede leer). */
    public function probeDuration(string $path): ?int
    {
        $bin = (string) config('media.ffprobe');

        if ($bin === '' || ! is_file($bin) || ! is_executable($bin)) {
            return null;
        }

        try {
            $process = new Process([$bin, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
            $process->setTimeout(20)->run();

            $seconds = (float) trim($process->getOutput());

            return $process->isSuccessful() && $seconds > 0 ? (int) ceil($seconds) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Comprime un video pendiente (lo corre el scheduler). Toma el video de a
     * uno (estado atómico) para no procesarlo dos veces; escribe en un
     * temporal y recién al terminar lo renombra. Si FFmpeg no puede leerlo,
     * el video queda "falló", se borra y el reporte lo explica.
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
        $temp = $targetFull.'.part';
        $height = (int) config('media.video_max_height');
        $fps = (int) config('media.video_max_fps');
        $maxSeconds = (int) config('media.video_max_seconds');
        $tooLong = false;

        try {
            $process = new Process([
                (string) config('media.ffmpeg'), '-nostdin', '-y', '-v', 'error', '-i', $source,
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale=-2:'min({$height},ih)'", '-fpsmax', (string) $fps,
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', (string) config('media.video_crf'),
                '-maxrate', (string) config('media.video_max_bitrate'), '-bufsize', ((int) config('media.video_max_bitrate') * 2).'k',
                '-pix_fmt', 'yuv420p', '-threads', (string) config('media.video_threads'),
                '-c:a', 'aac', '-b:a', '96k', '-ac', '2', '-movflags', '+faststart', '-f', 'mp4',
                // Nunca más que el máximo (+2 s para detectar si el original era más largo).
                '-t', (string) ($maxSeconds + 2), $temp,
            ]);
            $process->setTimeout(600)->run();

            if (! $process->isSuccessful() || ! is_file($temp) || filesize($temp) === 0) {
                throw new \RuntimeException('ffmpeg falló');
            }

            // Duración real: la del MP4 que escribió FFmpeg (no la que declaraba el archivo subido).
            $real = $this->mp4Duration($temp);
            if ($real === null || $real > $maxSeconds + 1) {
                $tooLong = $real !== null;

                throw new \RuntimeException('duración real fuera de límite');
            }

            if (! rename($temp, $targetFull)) {
                throw new \RuntimeException('no se pudo mover el temporal');
            }

            // Se queda la versión comprimida (MP4 H.264: se ve en todos los
            // navegadores), salvo que el original ya sea MP4/WEBM y más liviano.
            if (filesize($targetFull) < $video->size || ! $video->isWebPlayable()) {
                $old = $video->path;
                $video->forceFill(['path' => $target, 'mime' => 'video/mp4', 'size' => (int) filesize($targetFull), 'processing_note' => null])->save();
                Storage::disk('local')->delete($old);
            } else {
                Storage::disk('local')->delete($target);
                $video->forceFill(['processing_note' => null]);
            }

            $video->forceFill(['status' => ReportVideo::READY, 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            @unlink($temp);
            Storage::disk('local')->delete($target);
            Log::warning('No se pudo procesar un video de reporte', ['video_id' => $video->id, 'exception' => $e::class]);
            $video->deleteFile();
            $video->forceFill([
                'status' => ReportVideo::FAILED, 'processed_at' => now(),
                'processing_note' => $tooLong
                    ? 'El video dura más de '.$maxSeconds.' segundos. Subí uno más corto.'
                    : 'No se pudo procesar el video (archivo dañado o formato no compatible). Subilo de nuevo.',
            ])->save();
        }
    }

    /** Pendientes (y los que quedaron colgados) — lo llama media:process-videos. */
    public function processPending(int $max = 2): int
    {
        ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::PROCESSING)->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => ReportVideo::PENDING]);

        $done = 0;
        // Solo de empresas cuyo plan incluye videos: los dem�s quedan pendientes y se procesan si vuelven a tenerlo.
        $companies = ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::PENDING)->distinct()->pluck('company_id')
            ->filter(fn ($id) => static::allowedFor(Company::find($id)))->values();

        ReportVideo::withoutGlobalScopes()->where('status', ReportVideo::PENDING)->whereIn('company_id', $companies)->orderBy('id')->limit($max)->get()
            ->each(function (ReportVideo $video) use (&$done) {
                $this->process($video);
                $done++;
            });

        return $done;
    }
}
