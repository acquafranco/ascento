<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationInboxController;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Bandeja de avisos del proveedor (/proveedores/avisos). Misma regla que las
 * bandejas de técnicos y clientes: cada usuario ve, abre y marca solo SUS
 * avisos; abrir uno lo marca leído y lleva a su pantalla (que vuelve a
 * comprobar permisos), y solo a rutas del área de proveedores.
 */
class SupplierNotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('suppliers.notifications', [
            'notifications' => $user->notifications()->latest()->paginate(30),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        $row = $user->notifications()->whereKey($notification)->firstOrFail();

        if ($row->read_at === null) {
            $row->markAsRead();
            Realtime::notificationsChanged($user);
        }

        $path = NotificationInboxController::destinationFor($row);

        return redirect($path !== null && str_starts_with($path, '/proveedores') ? $path : route('suppliers.notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        if ($request->user()->unreadNotifications()->update(['read_at' => now()])) {
            Realtime::notificationsChanged($request->user());
        }

        return back()->with('status', 'Marcaste todos los avisos como leídos.');
    }

    public function seen(Request $request): Response
    {
        $ids = collect((array) $request->input('ids', []))->take(100)->filter(fn ($id) => is_string($id) && Str::isUuid($id))->values();
        if ($ids->isNotEmpty() && $request->user()->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()])) {
            Realtime::notificationsChanged($request->user());
        }

        return response()->noContent();
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()])->header('Cache-Control', 'no-store');
    }
}
