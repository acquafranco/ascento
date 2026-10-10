<?php

namespace App\Filament\Pages;

use App\Models\Backup;
use App\Services\Backups\BackupManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Backups globales de Ascento (base + archivos), solo para el SuperAdmin.
 * Distinto de "Exportar datos" (Excel de UNA empresa, no restaurable).
 */
class SystemBackups extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Backups';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'backups';

    protected static ?string $title = 'Backups de Ascento';

    protected string $view = 'filament.pages.system-backups';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Copia restaurable de toda la plataforma: base de datos y archivos. Automático todos los días a las 03:15.';
    }

    public function requestBackup(): void
    {
        abort_unless(static::canAccess(), 403);

        if (Backup::whereIn('status', [Backup::REQUESTED, Backup::RUNNING])->exists()) {
            Notification::make()->title('Ya hay un backup en curso')->warning()->send();

            return;
        }

        app(BackupManager::class)->request('manual', Auth::user());
        Notification::make()->title('Backup pedido')->body('Se genera en el próximo minuto (scheduler). Esta página se actualiza sola.')->success()->send();
    }

    public function verify(int $id): void
    {
        abort_unless(static::canAccess(), 403);

        $result = app(BackupManager::class)->verify(Backup::findOrFail($id));

        $result['ok']
            ? Notification::make()->title('Backup verificado')->body('Se abre, el checksum coincide y está completo.')->success()->send()
            : Notification::make()->title('El backup tiene problemas')->body(implode(' ', $result['problems']))->danger()->persistent()->send();
    }

    protected function getViewData(): array
    {
        $backups = Backup::with(['requester:id,name', 'downloads.user:id,name'])->latest('id')->limit(30)->get();

        return [
            'backups' => $backups,
            'pending' => $backups->contains(fn (Backup $b) => in_array($b->status, [Backup::REQUESTED, Backup::RUNNING], true)),
            'encrypted' => filled(config('backup.password')),
        ];
    }
}
