<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Models\Company;
use App\Models\Report;
use App\Services\Reports\ReportVideoService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Video del reporte en el panel: ver, subir (Profesional y Empresa) y quitar.
 * El plan, el tamaño y el formato los valida ReportVideoService en el
 * servidor (ocultar la acción no alcanza).
 */
class ReportVideoActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            Action::make('watchVideo')
                ->label('Ver video')
                ->icon('heroicon-o-play-circle')
                ->color('gray')
                ->modalHeading('Video del reporte')
                ->modalContent(fn (Report $record) => view('partials.report-video', ['video' => $record->video, 'src' => route('reports.video', $record)]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->visible(fn (Report $record) => $record->video !== null),
            Action::make('uploadVideo')
                ->label('Agregar video')
                ->icon('heroicon-o-video-camera')
                ->color('gray')
                ->schema([
                    FileUpload::make('video')
                        ->label('Video (MP4, MOV o WEBM)')
                        ->helperText('Uno por reporte. Hasta '.config('media.video_max_mb').' MB y '.config('media.video_max_seconds').' segundos.')
                        ->acceptedFileTypes(['video/mp4', 'video/quicktime', 'video/webm'])
                        ->maxSize(ReportVideoService::maxKb())
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (Report $record, array $data) {
                    try {
                        app(ReportVideoService::class)->store($record, $data['video'], auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title('No se pudo agregar el video')->body(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Video agregado')->success()->send();
                })
                ->visible(fn (Report $record) => $record->video === null && ReportVideoService::allowedFor(Company::find($record->company_id))),
            Action::make('deleteVideo')
                ->label('Quitar video')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (Report $record) {
                    $record->video && app(ReportVideoService::class)->delete($record->video);
                    Notification::make()->title('Video eliminado')->success()->send();
                })
                ->visible(fn (Report $record) => $record->video !== null),
        ];
    }
}
