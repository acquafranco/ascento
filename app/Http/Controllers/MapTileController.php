<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxy de mosaicos del mapa de Geoapify.
 *
 * Existe para que la API key quede en el servidor: el navegador pide
 * /map-tiles/{z}/{x}/{y}.png a Ascento y Ascento se lo pide a Geoapify.
 * El navegador cachea cada mosaico una semana, así que en la práctica
 * cada admin descarga los de su zona una sola vez.
 */
class MapTileController extends Controller
{
    public function __invoke(int $z, int $x, int $y): Response
    {
        $user = request()->user();

        abort_unless($user && ($user->isAdmin() || $user->isSuperAdmin()), 403);

        $max = 2 ** $z;
        abort_unless($z <= 20 && $x < $max && $y < $max, 404);

        $apiKey = config('services.geoapify.api_key');
        abort_unless(filled($apiKey), 404);

        $style = preg_replace('/[^a-z0-9\-]/', '', (string) config('services.geoapify.map_style', 'osm-bright'));

        try {
            $tile = Http::connectTimeout(5)
                ->timeout(10)
                ->get("https://maps.geoapify.com/v1/tile/{$style}/{$z}/{$x}/{$y}.png", ['apiKey' => $apiKey]);
        } catch (ConnectionException) {
            abort(502);
        }

        abort_unless($tile->successful(), 502);

        return response($tile->body(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }
}
