<?php

namespace App\Notifications\App;

use App\Filament\Pages\CompanyExports;
use App\Models\CompanyExport;
use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Al admin que la pidió: la exportación está lista o falló. Va también por
 * correo porque se genera en segundo plano y el admin puede haberse ido.
 * Nunca incluye el enlace de descarga directo ni el detalle técnico del error.
 */
class ExportFinishedNotification extends AppNotification
{
    public function __construct(public CompanyExport $export) {}

    private function ok(): bool
    {
        return $this->export->status === CompanyExport::COMPLETED;
    }

    public function title(): string
    {
        return $this->ok() ? 'Tu exportación está lista' : 'No se pudo generar la exportación';
    }

    public function body(): string
    {
        return $this->ok()
            ? 'Podés descargarla hasta el '.$this->export->expires_at?->format('d/m/Y').' ('.$this->export->sizeLabel().').'
            : 'Podés volver a intentarlo desde "Exportar datos". Si sigue fallando, escribinos.';
    }

    public function path(): ?string
    {
        return parse_url(CompanyExports::getUrl(panel: 'ascensores_app'), PHP_URL_PATH);
    }

    public function dedupeKey(): ?string
    {
        return 'export:'.$this->export->id.':'.$this->export->status;
    }

    public function actionLabel(): string
    {
        return 'Ir a exportaciones';
    }

    public function icon(): string
    {
        return $this->ok() ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-exclamation-triangle';
    }

    public function color(): string
    {
        return $this->ok() ? 'success' : 'danger';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->mail($this->title(), [$this->body(), 'La descarga requiere ingresar a Ascento con tu usuario de administrador.']);
    }
}
