<?php

namespace App\Http\Controllers;

use App\Services\Location\TechnicianLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ubicación del técnico autenticado (app del técnico). No recibe ids de
 * usuario ni de empresa: siempre es la del que tiene la sesión. Las
 * respuestas nunca repiten las coordenadas.
 */
class TechnicianLocationController extends Controller
{
    public function __construct(private TechnicianLocationService $locations) {}

    private function technician(Request $request)
    {
        $user = $request->user();
        abort_unless(TechnicianLocationService::canTrack($user), 403);

        return $user;
    }

    /** Activar en Ascento (lo hace el técnico, con el interruptor). */
    public function enable(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['permission' => ['nullable', 'string', 'in:granted,prompt,denied,unsupported']]);
        $this->locations->enable($this->technician($request), $data['permission'] ?? null);

        return $request->expectsJson() ? response()->json(['enabled' => true]) : redirect()->to(route('profile.edit', ['company' => $request->user()->company->slug]).'#ubicacion')->with('location_status', 'Activaste la ubicación en Ascento.');
    }

    public function disable(Request $request): JsonResponse|RedirectResponse
    {
        $this->locations->disable($this->technician($request));

        return $request->expectsJson() ? response()->json(['enabled' => false]) : redirect()->to(route('profile.edit', ['company' => $request->user()->company->slug]).'#ubicacion')->with('location_status', 'Desactivaste la ubicación. Ascento ya no recibe tu posición.');
    }

    /** Posición actual del técnico (solo mientras Ascento está abierto). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:'.(int) config('location.max_accuracy_meters', 5000)],
        ], [
            'latitude.*' => 'Ubicación inválida.',
            'longitude.*' => 'Ubicación inválida.',
            'accuracy.max' => 'La ubicación es demasiado imprecisa.',
        ]);

        // (0, 0) es lo que mandan algunos equipos sin señal: no es una posición real.
        if (abs((float) $data['latitude']) < 0.0001 && abs((float) $data['longitude']) < 0.0001) {
            return response()->json(['message' => 'Ubicación inválida.'], 422);
        }

        $result = $this->locations->record(
            $this->technician($request),
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
        );

        return response()->json(['result' => $result], $result === 'disabled' ? 409 : 200);
    }

    /** Permiso del navegador o un problema (sin coordenadas). */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'permission' => ['nullable', 'string', 'in:granted,prompt,denied,unsupported'],
            'error' => ['nullable', 'string', 'in:timeout,unavailable,insecure,unsupported'],
        ]);
        $this->locations->report($this->technician($request), $data['permission'] ?? null, $data['error'] ?? null);

        return response()->json(['ok' => true]);
    }
}
