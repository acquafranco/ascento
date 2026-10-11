<?php

namespace App\Http\Controllers;

use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Bandeja de avisos de técnicos (/notificaciones) y clientes del portal
 * (/portal/notificaciones). Los admins usan la campanita del panel.
 *
 * Cada usuario solo ve, abre y marca SUS avisos (se buscan siempre dentro de
 * $user->notifications(); el de otro usuario da 404). Abrir un aviso marca
 * leído y lleva a la pantalla destino, que vuelve a comprobar los permisos:
 * un aviso viejo no da acceso a lo que ya no corresponde.
 */
class NotificationInboxController extends Controller
{
    private function guard(Request $request, bool $portal)
    {
        $user = $request->user();

        if ($portal !== $user->isClientUser()) {
            // Cada perfil usa su bandeja.
            return $user->isClientUser() ? redirect()->route('portal.notifications') : redirect($user->homeUrl() ?? '/');
        }

        if (! $portal && $user->isAdmin()) {
            return redirect('/admin'); // campanita del panel
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

        if ($redirect = $this->guard($request, $portal)) {
            return $redirect;
        }

        /** @var DatabaseNotification $row */
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();
        Realtime::notificationsChanged($request->user()); // otras pestañas

        $path = $row->data['path'] ?? null;

        // Solo rutas internas (nunca a otro dominio).
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return redirect()->route($portal ? 'portal.notifications' : 'notifications.index');
        }

        return redirect($path);
    }

    public function readAll(Request $request)
    {
        $portal = $this->isPortal($request);

        if ($redirect = $this->guard($request, $portal)) {
            return $redirect;
        }

        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        Realtime::notificationsChanged($request->user());

        return back()->with('status', 'Marcaste todos los avisos como leídos.');
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()])
            ->header('Cache-Control', 'no-store');
    }
}
