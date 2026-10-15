<?php

namespace App\Support\Plans;

use App\Enums\PlanFeature;

/**
 * Catálogo de TODAS las funcionalidades de Ascento (Etapa 9.2): una sola
 * definición, por módulo y en orden cronológico, para la pantalla «Planes».
 *
 * Categorías:
 * - essential: en todos los planes; no se puede quitar (si tiene clave de
 *   plan, PlanFeature::structural() la protege también en el servidor).
 * - configurable: el SuperAdmin decide en qué planes está; la clave de
 *   PlanFeature es la que verifica el servidor (PlanGuard).
 * - role: depende del rol o de los permisos del usuario, no del plan.
 *
 * «origin» sale de la evidencia del repositorio (fecha y commit que la creó,
 * o la etapa documentada). Una función que forma parte de otra (partOf) se
 * habilita con ella: no tiene casilla propia.
 *
 * Cada caso de PlanFeature aparece exactamente una vez (lo verifica una
 * prueba), así el catálogo y el servidor no se separan.
 */
final class FeatureCatalog
{
    public const ESSENTIAL = 'essential';

    public const CONFIGURABLE = 'configurable';

    public const ROLE = 'role';

    public const CATEGORIES = [
        self::ESSENTIAL => 'Esencial',
        self::CONFIGURABLE => 'Configurable por plan',
        self::ROLE => 'Según rol o permisos',
    ];

    /** Módulos, en el orden en que se muestran. */
    public const MODULES = [
        'company' => 'Empresa y cuentas',
        'buildings' => 'Clientes, edificios y ascensores',
        'operations' => 'Operación',
        'delivery_notes' => 'Remitos',
        'commercial' => 'Comercial y administración',
        'insights' => 'Indicadores y alertas',
        'portal' => 'Portal de clientes',
        'technicians' => 'App del técnico',
        'notifications' => 'Avisos',
        'platform' => 'Plataforma (SuperAdmin)',
    ];

    /**
     * @return list<array{key: string, module: string, name: string, description: string, origin: string, category: string, feature: ?PlanFeature, partOf: ?PlanFeature}>
     */
    public static function entries(): array
    {
        $f = fn (string $module, PlanFeature $feature, string $description, string $origin, ?string $name = null) => [
            'key' => $feature->value, 'module' => $module, 'name' => $name ?? $feature->label(), 'description' => $description, 'origin' => $origin,
            'category' => $feature->isEssential() ? self::ESSENTIAL : self::CONFIGURABLE, 'feature' => $feature, 'partOf' => null,
        ];
        $x = fn (string $key, string $module, string $name, string $description, string $origin, string $category, ?PlanFeature $partOf = null) => [
            'key' => $key, 'module' => $module, 'name' => $name, 'description' => $description, 'origin' => $origin,
            'category' => $category, 'feature' => null, 'partOf' => $partOf,
        ];

        return [
            // Empresa y cuentas
            $x('company_settings', 'company', 'Mi empresa', 'Datos, logo y configuración de la empresa.', '27/07/2026 (ae3e4a9)', self::ROLE),
            $x('multi_company', 'company', 'Varias empresas en una plataforma', 'Cada empresa ve solo lo suyo (aislamiento por empresa).', '25/07/2026 (6080895)', self::ESSENTIAL),
            $x('subscription', 'company', 'Suscripción y pago', 'Plan, pago con Mercado Pago y estado de la cuenta (solo el dueño).', '08/08/2026 (745fe0d); Mercado Pago rehecho el 07/10/2026', self::ROLE),
            $x('onboarding', 'company', 'Primeros pasos y ayuda', 'Guía inicial y ayudas de cada pantalla.', '07/10/2026 (64915e5)', self::ESSENTIAL),
            $x('company_export', 'company', 'Exportación de datos', 'ZIP con un Excel y los archivos de la empresa (solo el dueño).', '09/10/2026 (fe57dbf)', self::ROLE),
            $x('staff_users', 'company', 'Usuarios administrativos y permisos', 'Personal de oficina con permisos por área. Cantidad según el plan.', 'Etapa 6 (12/10/2026, 4cbaa84)', self::ROLE),
            $x('activity_log', 'company', 'Registro de actividad', 'Registro de las acciones sensibles del panel; hoy se consulta en el historial de cada reparación.', 'Etapa 6 (12/10/2026, 4cbaa84)', self::ESSENTIAL),

            // Clientes, edificios y ascensores
            $f('buildings', PlanFeature::Clients, 'Alta y gestión de clientes.', 'Base original (02/07/2026, c0a17f7)'),
            $f('buildings', PlanFeature::Buildings, 'Alta y gestión de edificios. Cantidad según el plan.', 'Base original (02/07/2026, c0a17f7)'),
            $x('building_assignments', 'buildings', 'Técnicos asignados a cada edificio', 'Quién hace el mantenimiento y la inspección de cada edificio.', 'Base original (02/07/2026, c0a17f7)', self::ESSENTIAL),
            $f('buildings', PlanFeature::Map, 'Todos los edificios en un mapa, con ubicación automática, colores y zonas.', 'Etapa 1 (07/10/2026, b9985d2)'),
            $f('buildings', PlanFeature::ElevatorFile, 'Ficha técnica, documentación e historial básico de cada ascensor.', '08/10/2026 (957c779)', 'Legajo del ascensor'),
            $f('buildings', PlanFeature::ElevatorHistoryAdvanced, 'Historial completo con filtros y materiales usados.', '08/10/2026 (957c779)'),
            $f('buildings', PlanFeature::FailureAnalysis, 'Qué equipos se rompen seguido y por qué componente.', '08/10/2026 (957c779)'),
            $f('buildings', PlanFeature::PublicQr, 'Código por edificio con información administrativa para residentes y visitantes.', 'Etapa 7 (13/10/2026, 2b60fa3)'),
            $x('controller_brand', 'buildings', 'Marca y modelo del control', 'Datos del control en la ficha del ascensor.', 'Etapa 8.1 (13/10/2026, 8f5e16d)', self::ESSENTIAL, PlanFeature::ElevatorFile),

            // Operación
            $f('operations', PlanFeature::Maintenances, 'Visitas de mantenimiento con remito firmado.', 'Base original (02/07/2026, c0a17f7)'),
            $f('operations', PlanFeature::Inspections, 'Visitas de inspección con remito firmado.', 'Base original (02/07/2026, c0a17f7)'),
            $f('operations', PlanFeature::WorkOrders, 'Reclamos y trabajos asignados a técnicos, con participantes.', 'Base original (02/07/2026, c0a17f7); participantes 29-30/07/2026'),
            $f('operations', PlanFeature::History, 'Historial de lo hecho en cada edificio.', 'Sin fecha exacta; declarada en los planes el 08/10/2026 (ad5a64b)'),
            $f('operations', PlanFeature::Reports, 'Reportes de fallas con fotos. Cantidad por mes según el plan.', '03/08/2026 (27ae44b); fotos el 08/10/2026 (06023c0)', 'Reportes con fotos'),
            $x('stock', 'operations', 'Stock de repuestos', 'Entradas y salidas; se descuenta solo al declarar materiales en el remito.', '08/10/2026 (2edd74d)', self::ESSENTIAL),
            $f('operations', PlanFeature::Agenda, 'Lista mensual de mantenimientos e inspecciones según la frecuencia de cada edificio.', '08/10/2026 (957c779); frecuencias en la Etapa 5'),
            $f('operations', PlanFeature::ReportVideos, 'Un video por reporte para mostrar la falla.', '09/10/2026 (e25c145)'),
            $f('operations', PlanFeature::WorkPhotos, 'Fotos de antes y después e informes de inspección con fotos.', 'Etapa 5 (12/10/2026, 8297d4b)'),
            $f('operations', PlanFeature::Tasks, 'Tareas con responsable, vencimiento y recordatorio.', 'Etapa 6 (12/10/2026, 80680d1)'),
            $f('operations', PlanFeature::ParachuteTests, 'Registro de pruebas y avisos de las próximas o vencidas.', 'Etapa 7 (13/10/2026, 2b60fa3)'),
            $f('operations', PlanFeature::Repairs, 'Seguimiento de componentes enviados a un taller.', 'Etapa 8.4 (13/10/2026, c3ee1d4)'),
            $f('operations', PlanFeature::EmergencyRequests, 'Botón «Estoy atrapado en el ascensor» en el QR público: avisa a la empresa y a la administración del edificio. Lo activa cada empresa en «Mi empresa».', 'QR y pedidos de ayuda (15/10/2026)'),
            $f('operations', PlanFeature::OnCall, 'Guardias técnicas (noches, fines de semana y feriados): turnos por período, permanentes o rotativos, cambios con historial y pestaña «Guardias» del técnico.', 'Guardias técnicas (15/10/2026, 01c433f)'),

            // Remitos
            $x('signed_delivery_note', 'delivery_notes', 'Remito firmado', 'El técnico cierra cada trabajo con el remito y la firma.', 'Base original (02/07/2026, c0a17f7)', self::ESSENTIAL),
            $f('delivery_notes', PlanFeature::DigitalDeliveryNotes, 'PDF, enlace, WhatsApp o correo para el cliente.', 'Etapa 4; en los tres planes desde el #60 (13/10/2026, 638c71b)'),

            // Comercial y administración
            $f('commercial', PlanFeature::Quotes, 'Presupuestos numerados con PDF, enlace para el cliente y envío.', 'Base original (02/07/2026); ítems el 08/10/2026; envío e historial el 10/10/2026'),
            $x('services_receivables', 'commercial', 'Servicios y cobranzas', 'Contratos de mantenimiento, cobros por período y pagos.', '08/10/2026 (2edd74d)', self::ESSENTIAL),
            $f('commercial', PlanFeature::ClientContacts, 'Historial de contactos con cada cliente y seguimiento.', 'Etapa 6 (12/10/2026, 80680d1)'),

            // Indicadores y alertas
            $f('insights', PlanFeature::Indicators, 'Indicadores básicos de la operación.', '08/10/2026 (957c779)'),
            $f('insights', PlanFeature::AttentionCenter, 'Lo que necesita atención hoy: vencidos, trabados y urgentes.', '08/10/2026 (957c779)'),
            $f('insights', PlanFeature::AttentionAdvanced, 'Reincidencias, documentación vencida y trabajos atrasados.', '08/10/2026 (957c779)'),
            $f('insights', PlanFeature::CompanyIndicators, 'Evolución, técnicos, edificios y presupuestos.', '08/10/2026 (957c779)'),
            $f('insights', PlanFeature::AdvancedIndicators, 'Comparativas año contra año, cartera y tendencias.', '08/10/2026 (957c779)'),
            $f('insights', PlanFeature::AdvancedAlerts, 'Tendencias, contratos por vencer y deudas viejas.', '08/10/2026 (957c779)'),

            // Portal de clientes
            $f('portal', PlanFeature::ClientPortal, 'Cada consorcio ve lo que le compartís de sus edificios.', '09/10/2026 (4e3bcb0)'),
            $x('portal_responsibles', 'portal', 'Responsables visibles en el portal', 'Técnicos asignados a los edificios del cliente, con foto.', 'Etapa 8.3 (13/10/2026)', self::CONFIGURABLE, PlanFeature::ClientPortal),

            // App del técnico
            $x('technician_app', 'technicians', 'App del técnico', 'Visitas, órdenes, remitos y reportes desde el celular.', 'Base original (02/07/2026, c0a17f7)', self::ESSENTIAL),
            $f('technicians', PlanFeature::Technicians, 'Usuarios técnicos. Cantidad según el plan (las cuentas del portal no cuentan).', 'Base original (02/07/2026, c0a17f7)'),
            $x('technician_photo', 'technicians', 'Foto de perfil del técnico', 'Foto privada del técnico, visible para su empresa y sus clientes.', 'Etapa 8.2 (13/10/2026, 8f5e16d)', self::ESSENTIAL),

            // Avisos
            $x('in_app_notifications', 'notifications', 'Avisos en la app', 'Campanita con los avisos de cada usuario.', '03/08/2026 (27ae44b)', self::ESSENTIAL),
            $x('push', 'notifications', 'Notificaciones push', 'Avisos en el celular de técnicos, oficina y clientes.', '07/10/2026 (8e849e8)', self::ESSENTIAL),
            $x('telegram', 'notifications', 'Avisos por Telegram', 'Los técnicos pueden recibir sus avisos por Telegram.', '08/10/2026 (8a7db68)', self::ESSENTIAL),
            $x('visit_reminders', 'notifications', 'Recordatorios de visitas', 'Aviso diario de las visitas del mes a los técnicos.', '09/10/2026 (1ecd184)', self::ESSENTIAL),

            // Plataforma
            $x('platform_companies', 'platform', 'Empresas y entrar a una empresa', 'Administrar las empresas y operar dentro de una.', '30/07/2026 (00173c1)', self::ROLE),
            $x('platform_subscriptions', 'platform', 'Suscripciones', 'Estado y pagos de las suscripciones de cada empresa.', '08/10/2026 (8a7db68)', self::ROLE),
            $x('platform_backups', 'platform', 'Backups', 'Copias restaurables de toda la plataforma.', '10/10/2026 (9937b92)', self::ROLE),
            $x('platform_overview', 'platform', 'Panorama de la plataforma', 'Salud operativa de todas las empresas.', '11/10/2026 (5753fab)', self::ROLE),
            $x('platform_erase', 'platform', 'Borrado definitivo de empresas', 'Elimina una empresa con sus datos y archivos, con confirmación.', '12/10/2026 (6fc099d)', self::ROLE),
            $x('platform_plans', 'platform', 'Planes y funciones', 'Este catálogo: qué incluye cada plan, con su historial de cambios.', 'Etapa 9 (13/10/2026, e2e68e3)', self::ROLE),
        ];
    }

    /** Las entradas de un módulo, en orden. @return list<array> */
    public static function byModule(): array
    {
        $grouped = array_fill_keys(array_keys(self::MODULES), []);
        foreach (self::entries() as $entry) {
            $grouped[$entry['module']][] = $entry;
        }

        return array_filter($grouped);
    }
}
