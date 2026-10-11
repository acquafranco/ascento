<?php

namespace App\Http\Controllers;

use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Bandeja de avisos de técnicos (/notificaciones) y clientes del portal
 * (/portal/notificaciones). Los admins usan la campanita del panel.
 *
 * Cada usuario solo ve, abre y marca SUS avisos (se buscan siempre dentro de
 * $user->notifications(); el de otro usuario da 404). Abrir un aviso marca
 * leído y lleva a la pantalla destino, que vuelve a comprobar los permisos:
 * un aviso viejo no da acceso a lo que ya no corresponde.
 *
 * Los admins y el SuperAdmin usan la campanita del panel, pero sus avisos
 * también se abren por acá (open): así se marcan leídos en el servidor antes
 * de redirigir, igual que los de técnicos y clientes.
 *
 * "Vistos": los avisos sin leer que llegaron a verse en pantalla en la
 * bandeja se marcan leídos al salir de ella (seen). Los que no se vieron
 * quedan sin leer.
 */
class NotificationInboxController extends Controller
{
    private const MAX_SEEN_IDS = 100;

    private function guard(Request $request, bool $portal, bool $panelUsersAllowed = false)
    {
        $user = $request->user();

        if ($portal !== $user->isClientUser()) {
            // Cada perfil usa su bandeja.
            return $user->isClientUser() ? redirect()->route('portal.notifications') : redirect($user->homeUrl() ?? '/');
        }

        if (! $portal && ! $panelUsersAllowed && ($user->isAdmin() || $user->isSuperAdmin())) {
            return redirect('/admin'); // campanita del panel
        }

        return null;
    }

    /**
     * Destino interno del aviso: su "path", el botón con el que se guardó o el
     * reporte (avisos viejos con solo report_id). Nunca otro dominio.
     */
    public static function destinationFor(DatabaseNotification $row): ?string
    {
        $data = (array) $row->data;
        $candidates = [$data['path'] ?? null, ...array_column((array) ($data['actions'] ?? []), 'url')];

        if (isset($data['report_id']) && is_numeric($data['report_id'])) {
            $candidates[] = route('filament.ascensores_app.resources.reports.view', ['record' => (int) $data['report_id']], false);
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//') && ! str_starts_with($candidate, '/\\')) {
                return $candidate;
            }

            $app = parse_url((string) config('app.url'));
            $url = parse_url($candidate);
            if (isset($url['host'], $app['host']) && strcasecmp($url['host'], $app['host']) === 0) {
                return ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
            }
        }

        return null;
    }

    private function isPortal(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'portal.');
    }

    public function index(Request $request)
    {
        $portal = $this->isPortal($request);

        if ($redirect = $this->guard($request, $portal)) {
            return $redirect;
        }

        $user = $request->user();

        return view($portal ? 'portal.notifications' : 'notifications.index', [
            'user' => $user,
            'company' => $user->company,
            'notifications' => $user->notifications()->latest()->paginate(30),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification)
    {
        $portal = $this->isPortal($request);

        if ($redirect = $this->guard($request, $portal, panelUsersAllowed: true)) {
            return $redirect;
        }

        $user = $request->user();

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->whereKey($notification)->firstOrFail();

        if ($row->read_at === null) { // repetir el enlace no vuelve a avisar
            $row->markAsRead();
            Realtime::notificationsChanged($user); // otras pestañas
        }

        // Solo rutas internas (nunca a otro dominio).
        $path = static::destinationFor($row);

        if ($path === null) {
            return $portal ? redirect()->route('portal.notifications')
                : (($user->isAdmin() || $user->isSuperAdmin()) ? redirect('/admin') : redirect()->route('notifications.index'));
        }

        return redirect($path);
    }

    /** Avisos que se vieron en la bandeja (se mandan al salir de la página). */
    public function seen(Request $request)
    {
        $portal = $this->isPortal($request);

        if ($this->guard($request, $portal)) {
            return response()->noContent(403);
        }

        $ids = collect((array) $request->input('ids', []))
            ->take(self::MAX_SEEN_IDS)
            ->filter(fn ($id) => is_string($id) && Str::isUuid($id))
            ->values();

        if ($ids->isNotEmpty() && $request->user()->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()])) {
            Realtime::notificationsChanged($request->user());
        }

        return response()->noContent();
    }

    public function readAll(Request $request)
    {
        $portal = $this->isPortal($request);

        if ($redirect = $this->guard($request, $portal)) {
            return $redirect;
        }

        if ($request->user()->unreadNotifications()->update(['read_at' => now()])) {
            Realtime::notificationsChanged($request->user());
        }

        return back()->with('status', 'Marcaste todos los avisos como leídos.');
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()])
            ->header('Cache-Control', 'no-store');
    }
}
