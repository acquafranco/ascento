<?php

/*
| Ubicación de los técnicos (mapa del administrador). Solo se guarda la
| ÚLTIMA posición de cada técnico, no un recorrido.
*/
return [
    // Cada cuántos segundos el celular manda la posición, como mucho (mientras Ascento está abierto).
    'send_interval' => (int) env('LOCATION_SEND_INTERVAL', 60),

    // Una posición más vieja que esto se muestra como «desactualizada».
    'fresh_seconds' => (int) env('LOCATION_FRESH_SECONDS', 300),

    // El servidor ignora envíos más seguidos que esto (además del límite por minuto).
    'min_seconds_between' => (int) env('LOCATION_MIN_SECONDS_BETWEEN', 20),

    // Pasado este tiempo sin novedades, las coordenadas se borran (location:prune).
    'keep_hours' => (int) env('LOCATION_KEEP_HOURS', 12),

    // Precisión peor que esto (en metros) se rechaza; peor que «approximate_meters», se avisa como aproximada.
    'max_accuracy_meters' => 5000,
    'approximate_meters' => 150,

    // Cada cuánto el mapa del administrador busca novedades.
    'map_refresh_seconds' => (int) env('LOCATION_MAP_REFRESH_SECONDS', 30),
];
