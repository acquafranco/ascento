<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Models\Report;
use Filament\Actions\Action;

/** Botón "PDF" del reporte (lo genera el servidor, con permisos). */
class ReportPdfAction
{
    public static function make(): Action
    {
        return Action::make('pdf')
            ->label('PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->url(fn (Report $record) => route('reports.pdf', $record))
            ->openUrlInNewTab();
    }
}
