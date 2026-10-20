<?php

namespace App\Support;

use App\Enums\PlanFeature;
use App\Support\Plans\PlanGuard;

/**
 * Secciones de la app del técnico: una sola fuente para la barra de
 * escritorio, la barra inferior del celular y el menú "Más". Se calcula una
 * vez por request (el contador de avisos es una consulta).
 */
class TechNav
{
    /** @return array{main: array, more: array, unread: int, slug: ?string} */
    public static function get(): array
    {
        $request = request();
        if ($request->attributes->has('tech_nav')) {
            return $request->attributes->get('tech_nav');
        }

        $user = $request->user();
        $slug = $user?->company?->slug;
        $item = fn (string $label, string $route, array $active, string $icon) => [
            'label' => $label,
            'href' => route($route, ['company' => $slug]),
            'active' => $request->routeIs(...$active),
            'icon' => $icon,
        ];

        $nav = [
            'slug' => $slug,
            'main' => [
                $item('Inicio', 'dashboard', ['dashboard'], 'home'),
                $item('Visitas', 'buildings.index', ['buildings.index', 'delivery-notes.building'], 'calendar'),
                $item('Trabajos', 'work-orders.index', ['work-orders.*', 'delivery-notes.work-order'], 'wrench'),
                $item('Reportes', 'reports.index', ['reports.*'], 'report'),
                // Guardias técnicas: en los planes que las incluyen (hoy, los tres).
                ...($user?->company_id && PlanGuard::companyAllows((int) $user->company_id, PlanFeature::OnCall)
                    ? [$item('Guardias', 'on-call.index', ['on-call.*'], 'phone')] : []),
            ],
            'more' => [
                $item('Remitos', 'delivery-notes.index', ['delivery-notes.index', 'delivery-notes.show'], 'document'),
                $item('Mi planilla', 'templates.index', ['templates.*'], 'table'),
                // Pruebas de paracaídas de sus equipos (en los planes que las incluyen).
                ...($user?->company_id && PlanGuard::companyAllows((int) $user->company_id, PlanFeature::ParachuteTests)
                    ? [$item('Paracaídas', 'parachute.index', ['parachute.*'], 'shield')] : []),
                $item('Todos los edificios', 'buildings.all', ['buildings.all'], 'building'),
                $item('Ayuda', 'help.technician', ['help.technician'], 'help'),
            ],
            'unread' => $user?->role === 'technician' ? $user->unreadNotifications()->count() : 0,
        ];

        $request->attributes->set('tech_nav', $nav);

        return $nav;
    }

    /** Trazos SVG (24×24, línea) de cada ícono. */
    public static function icon(string $name): string
    {
        return match ($name) {
            'home' => '<path d="M4 11 12 4l8 7v8a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1v-8Z"/>',
            'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="m9 15 2 2 4-4"/>',
            'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.3 5.3L4 17v3h3l5.4-5.4a4 4 0 0 0 5.3-5.3l-2.4 2.4-2.6-.6-.6-2.6 2.6-2.2Z"/>',
            'report' => '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h7M9 17h5"/>',
            'document' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M10 12h5M10 16h5"/>',
            'table' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M9 9.5v10"/>',
            'building' => '<path d="M5 21V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v17M15 10h3a1 1 0 0 1 1 1v10M3 21h18M8.5 7h3M8.5 11h3M8.5 15h3"/>',
            'bell' => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1-3.5 3.7-5 7-5s6 1.5 7 5"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'more' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'pin' => '<path d="M12 21s-7-6.5-7-11.5a7 7 0 0 1 14 0C19 14.5 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
            'chevron' => '<path d="m9 6 6 6-6 6"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.9.7c0 1.7-2.4 2.1-2.4 3.6M12 17h.01"/>',
            'check' => '<path d="m5 12 4.5 4.5L19 7"/>',
            'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
            'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
            'camera' => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
            default => '',
        };
    }
}
