<?php

namespace App\Filament\Resources\Maintenances\Schemas;

use App\Filament\Support\JobDetail;
use Filament\Schemas\Schema;

/** Detalle de un mantenimiento: datos, enlace al remito y fotos de antes y después por zona. */
class MaintenanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            JobDetail::summary()->columnSpanFull(),
            JobDetail::photos()->columnSpanFull(),
            JobDetail::planLocked('Fotos de antes y después')->columnSpanFull(),
        ]);
    }
}
