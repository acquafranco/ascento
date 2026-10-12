<?php

namespace App\Filament\Resources\Inspections\Schemas;

use App\Filament\Support\JobDetail;
use Filament\Schemas\Schema;

/** Detalle de una inspección: datos, enlace al remito y su reporte («Ver reporte»). */
class InspectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            JobDetail::summary()->columnSpanFull(),
            JobDetail::inspectionReport()->columnSpanFull(),
            JobDetail::legacyInspectionPhotos()->columnSpanFull(),
        ]);
    }
}
