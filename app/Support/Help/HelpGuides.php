<?php

namespace App\Support\Help;

use App\Filament\Pages\HelpCenter;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\Help\Content\HelpFaq;
use App\Support\Help\Content\PanelGuides;
use App\Support\Help\Content\PortalGuides;
use App\Support\Help\Content\TechnicianGuides;
use App\Support\Plans\PlanGuard;
use App\Support\Portal\PortalAccess;

/**
 * Centro de ayuda de Ascento: una sola biblioteca de artículos para el panel
 * de la empresa, la app del técnico y el portal del cliente (el contenido
 * está en Content\*: un archivo por perfil, más las preguntas frecuentes). Cada guía dice
 * a quién está dirigida, qué función del plan necesita y, en el panel, qué
 * área de permisos (o si es solo del dueño): cada usuario ve solo las que
 * puede usar. Los textos describen el comportamiento real de la app.
 *
 * Las ayudas cortas de cada pantalla (botón «?» y tarjetas) siguen en
 * AdminOnboarding::sectionHelp y HelpTopics; acá van los pasos completos.
 */
final class HelpGuides
{
    public const PANEL = 'panel';

    public const TECHNICIAN = 'technician';

    public const PORTAL = 'portal';

    public const TROUBLESHOOTING = 'Solución de problemas frecuentes';

    /**
     * Categorías del Centro de ayuda, por perfil y en orden: agrupan por la
     * tarea que la persona quiere hacer, no por pantalla.
     *
     * @return array<string, array{audience: string, title: string, description: string, icon: string}>
     */
    public static function categories(): array
    {
        $c = fn (string $audience, string $title, string $description, string $icon) => compact('audience', 'title', 'description', 'icon');

        return [
            'primeros-pasos' => $c(self::PANEL, 'Primeros pasos con Ascento', 'Cómo moverte por Ascento y en qué orden empezar.', 'rocket-launch'),
            'empresa' => $c(self::PANEL, 'Configurar mi empresa', 'Datos, logo, fotos de los trabajos y avisos.', 'building-office'),
            'clientes-edificios' => $c(self::PANEL, 'Clientes, edificios y ascensores', 'Cargar la cartera, los equipos y asignar técnicos.', 'building-office-2'),
            'mantenimientos' => $c(self::PANEL, 'Mantenimientos e inspecciones', 'La agenda del mes, las visitas y las pruebas de paracaídas.', 'calendar-days'),
            'tecnico' => $c(self::PANEL, 'Cómo trabaja el técnico', 'Qué ve y qué hace cada técnico desde su celular.', 'device-phone-mobile'),
            'ordenes' => $c(self::PANEL, 'Órdenes de trabajo y reportes', 'Reclamos, trabajos puntuales, fallas y materiales.', 'wrench'),
            'remitos' => $c(self::PANEL, 'Remitos digitales e historial', 'Firmas, PDF e historial de cada equipo.', 'document-check'),
            'presupuestos' => $c(self::PANEL, 'Presupuestos, reparaciones y repuestos', 'Presupuestos, componentes en taller y stock.', 'calculator'),
            'servicios' => $c(self::PANEL, 'Servicios contratados y cobranzas', 'Contratos, cobros por período y pagos.', 'banknotes'),
            'mapa-qr' => $c(self::PANEL, 'Mapa, QR y pedidos de ayuda', 'El mapa de la cartera, el QR público y los pedidos por atrapamiento.', 'qr-code'),
            'guardias' => $c(self::PANEL, 'Guardias técnicas', 'Turnos fuera de horario, rotaciones y reemplazos.', 'shield-check'),
            'portal' => $c(self::PANEL, 'Portal de clientes', 'Accesos por edificio y qué ve cada cliente.', 'user-group'),
            'seguimiento' => $c(self::PANEL, 'Indicadores, tareas y seguimiento', 'Qué necesita atención, tendencias y pendientes de la oficina.', 'chart-bar'),
            'usuarios' => $c(self::PANEL, 'Usuarios, permisos y seguridad', 'Técnicos, administrativos, permisos y contraseñas.', 'identification'),
            'cuenta' => $c(self::PANEL, 'Plan, suscripción y exportación', 'Tu plan, sus límites y la copia de tus datos.', 'credit-card'),
            'problemas' => $c(self::PANEL, self::TROUBLESHOOTING, 'Lo que más se consulta y cómo resolverlo.', 'lifebuoy'),
            'plataforma' => $c(self::PANEL, 'Herramientas de SuperAdmin', 'Empresas, suscripciones, planes y backups.', 'server-stack'),

            't-dia' => $c(self::TECHNICIAN, 'Tu día de trabajo', 'Visitas, remitos y fotos.', 'calendar-days'),
            't-ordenes' => $c(self::TECHNICIAN, 'Trabajos y reportes', 'Órdenes de la oficina y fallas que detectás.', 'wrench'),
            't-guardias' => $c(self::TECHNICIAN, 'Guardias', 'Cuándo te toca cubrir y los cambios.', 'shield-check'),
            't-cuenta' => $c(self::TECHNICIAN, 'Tu cuenta y avisos', 'Perfil, foto y avisos en el celular.', 'user-circle'),
            't-problemas' => $c(self::TECHNICIAN, self::TROUBLESHOOTING, 'Lo que más se consulta.', 'lifebuoy'),

            'p-portal' => $c(self::PORTAL, 'Tu portal', 'Qué ves y de qué empresa.', 'home'),
            'p-documentos' => $c(self::PORTAL, 'Documentos y visitas', 'Remitos, presupuestos y visitas de tus edificios.', 'document-text'),
            'p-ayuda' => $c(self::PORTAL, 'Reclamos y pedidos de ayuda', 'Reclamos a tu empresa y pedidos desde el QR.', 'exclamation-triangle'),
            'p-cuenta' => $c(self::PORTAL, 'Avisos y cuenta', 'Avisos en el celular y contraseña.', 'bell'),
            'p-problemas' => $c(self::PORTAL, self::TROUBLESHOOTING, 'Lo que más se consulta.', 'lifebuoy'),
        ];
    }

    /**
     * Arma un artículo con todos sus campos. `module` es el título de su
     * categoría (lo usan el filtro y los grupos).
     *
     * Opciones: before (requisitos), after (qué sucede después), notes,
     * problems ([pregunta, respuesta]), related (claves), keywords (palabras
     * habituales y sinónimos), flow (cadena de pasos), image (captura real,
     * src/alt/caption), open (pantalla del panel para «Abrir la sección»),
     * feature (PlanFeature), area (PermissionArea), owner (solo el dueño),
     * super (solo el SuperAdmin).
     */
    public static function make(string $audience, string $category, string $key, string $title, string $summary, array $steps, array $o = []): array
    {
        return [
            'key' => $key,
            'audience' => $audience,
            'category' => $category,
            'module' => self::categories()[$category]['title'] ?? $category,
            'title' => $title,
            'summary' => $summary,
            'before' => $o['before'] ?? [],
            'steps' => $steps,
            'after' => $o['after'] ?? null,
            'notes' => $o['notes'] ?? [],
            'problems' => $o['problems'] ?? [],
            'related' => $o['related'] ?? [],
            'keywords' => $o['keywords'] ?? [],
            'flow' => $o['flow'] ?? null,
            'image' => $o['image'] ?? null,
            'open' => $o['open'] ?? null,
            'feature' => $o['feature'] ?? null,
            'area' => $o['area'] ?? null,
            'owner' => (bool) ($o['owner'] ?? false),
            'super' => (bool) ($o['super'] ?? false),
        ];
    }

    /** @return list<array> Todos los artículos, de los tres perfiles. */
    public static function all(): array
    {
        return self::$all ??= [...PanelGuides::all(), ...TechnicianGuides::all(), ...PortalGuides::all()];
    }

    private static ?array $all = null;

    /** Guías que esta persona puede usar, en orden. @return list<array> */
    public static function for(?User $user, string $audience): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(self::all(), fn (array $guide) => $guide['audience'] === $audience && self::allows($user, $guide)));
    }

    public static function find(?User $user, string $audience, string $key): ?array
    {
        return collect(self::for($user, $audience))->firstWhere('key', $key);
    }

    /** Las mismas reglas sirven para artículos y preguntas frecuentes. */
    public static function allows(User $user, array $guide): bool
    {
        return match ($guide['audience']) {
            self::PANEL => Access::panelUser($user)
                && (! ($guide['super'] ?? false) || $user->isSuperAdmin())
                && (! $guide['owner'] || $user->isSuperAdmin() || Access::isOwner($user))
                && ($guide['area'] === null || Access::can($user, $guide['area']))
                && ($guide['feature'] === null || PlanGuard::currentAllows($guide['feature'])),
            self::TECHNICIAN => $user->role === 'technician' && $user->company_id !== null
                && ($guide['feature'] === null || PlanGuard::companyAllows((int) $user->company_id, $guide['feature'])),
            self::PORTAL => $user->isClientUser() && ($companyId = PortalAccess::currentCompanyId($user)) !== null
                && ($guide['feature'] === null || PlanGuard::companyAllows($companyId, $guide['feature'])),
            default => false,
        };
    }

    /**
     * Categorías con al menos un artículo para esta persona, en orden, con sus
     * artículos. @return array<string, array{title: string, description: string, icon: string, guides: list<array>}>
     */
    public static function categoriesFor(?User $user, string $audience): array
    {
        $guides = collect(self::for($user, $audience))->groupBy('category');
        $out = [];
        foreach (self::categories() as $slug => $category) {
            if ($category['audience'] === $audience && $guides->has($slug)) {
                $out[$slug] = $category + ['guides' => $guides[$slug]->values()->all()];
            }
        }

        return $out;
    }

    /** Preguntas frecuentes que esta persona puede usar. @return list<array> */
    public static function faqFor(?User $user, string $audience): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(HelpFaq::all(), fn (array $faq) => $faq['audience'] === $audience
            && self::allows($user, $faq)
            && ($faq['guide'] === null || self::find($user, $audience, $faq['guide']) !== null)));
    }

    /** Artículos relacionados que esta persona puede abrir (nunca enlaces a algo que no ve). @return list<array> */
    public static function relatedFor(?User $user, string $audience, array $guide): array
    {
        return array_values(array_filter(array_map(fn (string $key) => self::find($user, $audience, $key), $guide['related'] ?? [])));
    }

    /**
     * «Abrir la sección» de un artículo del panel: solo si la persona puede
     * entrar a esa pantalla (la pantalla lo vuelve a verificar al abrirse).
     */
    public static function openUrl(array $guide): ?string
    {
        $class = $guide['open'] ?? null;
        if (! $class || ($guide['audience'] ?? null) !== self::PANEL || ! class_exists($class)) {
            return null;
        }

        try {
            return $class::canAccess() ? $class::getUrl() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dirección de una guía en la ayuda de su perfil (?guia=clave#guia-clave). */
    public static function url(string $audience, string $key, array $extra = []): string
    {
        $base = match ($audience) {
            self::TECHNICIAN => route('help.technician', ['company' => auth()->user()?->company?->slug]),
            self::PORTAL => route('portal.help'),
            default => HelpCenter::getUrl(),
        };

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['guia' => $key] + $extra).'#guia-'.$key;
    }

    /** Agrupadas por módulo (los problemas frecuentes, al final). @return array<string, list<array>> */
    public static function grouped(array $guides): array
    {
        $grouped = [];
        foreach ($guides as $guide) {
            $grouped[$guide['module']][] = $guide;
        }
        if (isset($grouped[self::TROUBLESHOOTING])) {
            $last = $grouped[self::TROUBLESHOOTING];
            unset($grouped[self::TROUBLESHOOTING]);
            $grouped[self::TROUBLESHOOTING] = $last;
        }

        return $grouped;
    }
}
