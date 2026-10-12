<?php

/*
|--------------------------------------------------------------------------
| Fotos y videos de reportes
|--------------------------------------------------------------------------
|
| Límites pensados para el servidor actual (un solo servidor, disco local
| privado). Ver docs/operacion-produccion.md: el límite de subida de PHP y
| de Nginx tiene que ser MAYOR que el tamaño máximo del video.
|
*/

return [

    // Fotos: lado máximo, calidad JPEG y miniatura (para listas y el portal).
    'photo_max_side' => 2000,
    'photo_quality' => 82,
    'thumb_side' => 480,
    // Megapíxeles máximos de una foto ANTES de decodificarla: una imagen
    // pequeña en bytes pero gigante en píxeles agotaría la memoria de PHP.
    // 50 MP cubre las cámaras de 48 MP de los celulares.
    'photo_max_pixels' => 50_000_000,

    // Video: uno por reporte (planes Profesional y Empresa).
    'video_max_mb' => (int) env('MEDIA_VIDEO_MAX_MB', 50),
    'video_max_seconds' => (int) env('MEDIA_VIDEO_MAX_SECONDS', 90),

    // FFmpeg es opcional: si está, los videos se comprimen a MP4 H.264 (se ven
    // en cualquier navegador) en segundo plano. Si no, se guardan como llegan.
    'ffmpeg' => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', '/usr/bin/ffprobe'),
    // Compresión: 720p, H.264 + AAC, calidad constante (CRF 26) con techo de
    // bitrate para que un video movido no quede enorme. Alcanza para ver
    // componentes, desgaste y fallas, y se reproduce fluido en el celular.
    'video_max_height' => 720,
    'video_crf' => 26,
    'video_max_bitrate' => '2500k',
    'video_max_fps' => 30,
    'video_threads' => 2,

];
