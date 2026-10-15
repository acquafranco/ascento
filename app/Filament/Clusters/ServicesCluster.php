<?php

namespace App\Filament\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * «Servicios»: una sola sección con dos vistas que no se mezclan.
 *
 * - Servicios contratados (MaintenanceServiceResource): lo que cada cliente tiene
 *   contratado y genera cobros.
 * - Servicios ofrecidos (ServiceCatalogItemResource): el catálogo, con precios de
 *   referencia; no cobra ni cubre visitas por sí solo.
 *
 * Al entrar abre la primera vista a la que el usuario tiene acceso.
 */
class ServicesCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Servicios';

    protected static ?string $clusterBreadcrumb = 'Servicios';

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'servicios';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /** Solo con acceso a alguna de sus vistas (permiso de Cobranzas): nunca una página vacía. */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
