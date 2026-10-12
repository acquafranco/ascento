<?php

namespace App\Filament\Resources\Reports\Schemas;

use App\Models\Building;
use App\Models\Report;
use App\Services\Reports\ReportPhotoService;
use App\Support\ElevatorComponents;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('building_id')
                    ->label('Edificio')
                    ->relationship('building', 'name', fn ($query) => $query->withoutTrashed())
                    ->searchable()
                    ->preload()
                    ->live()
                    // El reporte de una inspección queda en el edificio de su visita.
                    ->disabled(fn (?Report $record) => (bool) $record?->isInspection())
                    ->required(),
                Select::make('elevator_number')
                    ->label('Ascensor')
                    // Mismos valores que guarda la app del técnico ("Ascensor 1").
                    // Si el reporte tiene otro valor (dato viejo), se conserva.
                    ->options(function (callable $get, ?Report $record): array {
                        $building = Building::find($get('building_id'));
                        $labels = $building?->unitLabels() ?? [];
                        $current = $record?->elevator_number;

                        if (filled($current) && ! in_array($current, $labels, true)) {
                            $labels[] = $current;
                        }

                        return array_combine($labels, $labels) ?: [];
                    })
                    ->live()
                    ->visible(fn (?Report $record) => ! $record?->isInspection())
                    ->required(),
                Select::make('component')
                    ->label('Componente afectado (opcional)')
                    ->visible(fn (?Report $record) => ! $record?->isInspection())
                    ->options(ElevatorComponents::LIST)
                    ->helperText('Sirve para el análisis de fallas del ascensor.'),
                // Las fotos NO las guarda Filament: las procesa ReportPhotoService
                // (re-codifica, achica, disco privado). Las ya cargadas se ven y
                // se borran en la pestaña "Fotos".
                FileUpload::make('new_photos')
                    ->label(fn (?Report $record) => $record ? 'Agregar fotos' : 'Fotos (opcional)')
                    ->helperText('Hasta '.ReportPhotoService::MAX_PHOTOS.' fotos por reporte. JPG, PNG o WEBP, hasta 10 MB cada una.')
                    ->multiple()
                    ->image()
                    // image() acepta image/* (incluye SVG). Solo formatos raster.
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(ReportPhotoService::MAX_KB)
                    ->maxFiles(fn (?Report $record) => max(0, ReportPhotoService::MAX_PHOTOS - ($record?->photos()->count() ?? 0)))
                    ->storeFiles(false)
                    ->dehydrated(true)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(fn (?Report $record) => $record?->isInspection() ? 'Observaciones de la inspección' : 'Descripción')
                    ->required()
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Textarea::make('observations')
                    ->label('Observaciones / seguimiento')
                    ->helperText('Notas internas de la oficina. Salen en el PDF del reporte.')
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Select::make('priority')
                    ->label('Prioridad')
                    ->options(['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Critica'])
                    ->default('baja')
                    // Una inspección no es una reparación: sin prioridad ni estado.
                    ->visible(fn (?Report $record) => ! $record?->isInspection())
                    ->required(),
                Select::make('status')
                    ->label('Estado')
                    ->options(['pendiente' => 'Pendiente', 'en_revision' => 'En revision', 'resuelto' => 'Resuelto'])
                    ->default('pendiente')
                    ->visible(fn (?Report $record) => ! $record?->isInspection())
                    ->required(),
            ]);
    }
}
