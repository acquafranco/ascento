<?php

namespace App\Filament\Resources\Companies;

use App\Models\Company;
use App\Services\Companies\CompanyEraser;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * «Eliminar definitivamente» una empresa (solo SuperAdmin), desde su ficha o
 * desde la lista (también desactivadas). Muestra qué empresa es (nombre,
 * razón social, CUIT, correo, slug) y cuánto se borra; pide escribir el
 * nombre exacto y confirmar que no se puede deshacer. El borrado y sus
 * bloqueos los hace CompanyEraser (el servidor vuelve a verificar todo).
 */
final class CompanyDeletion
{
    public static function action(): Action
    {
        return Action::make('eraseCompany')
            ->label('Eliminar definitivamente')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize(fn () => (bool) auth()->user()?->isSuperAdmin())
            ->visible(fn () => (bool) auth()->user()?->isSuperAdmin())
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(fn (Company $record) => 'Eliminar definitivamente «'.$record->name.'»')
            ->modalDescription(fn (Company $record) => self::summary($record))
            ->modalSubmitActionLabel('Eliminar definitivamente')
            ->schema([
                TextInput::make('confirmation')
                    ->label(fn (Company $record) => 'Para confirmar, escribí el nombre de la empresa: '.$record->name)
                    ->required()
                    ->autocomplete(false)
                    ->rule(fn (Company $record) => fn (string $attribute, $value, \Closure $fail) => trim((string) $value) === $record->name
                        ? null : $fail('El nombre no coincide.')),
                Checkbox::make('understood')
                    ->label('Entiendo que se borran todos sus datos, usuarios y archivos, y que no se puede deshacer.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirmá que entendés que no se puede deshacer.']),
            ])
            ->action(function (Company $record, array $data, Action $action) {
                $name = $record->name;

                try {
                    $result = app(CompanyEraser::class)->erase($record, auth()->user(), (string) $data['confirmation']);
                } catch (ValidationException $e) {
                    Notification::make()->title('No se eliminó la empresa')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Empresa eliminada definitivamente')
                    ->body("Se borraron «{$name}», {$result['rows']} registros y {$result['files']} archivos.")
                    ->success()
                    ->send();

                return redirect(CompanyResource::getUrl('index'));
            });
    }

    /** Qué empresa es y qué se borra (descripción del modal). */
    public static function summary(Company $record): HtmlString
    {
        $preview = app(CompanyEraser::class)->preview($record);
        $e = fn ($v) => e((string) $v);

        $who = collect([
            'Razón social' => $record->business_name,
            $record->taxIdLabel() => $record->cuit,
            'Correo' => $record->email,
            'Identificador' => $record->slug.' (#'.$record->id.')',
            'Estado' => $record->trashed() ? 'Desactivada' : 'Activa',
        ])->filter()->map(fn ($v, $k) => '<li><strong>'.$e($k).':</strong> '.$e($v).'</li>')->implode('');

        $counts = collect($preview['counts'])->filter()->map(fn ($n, $k) => '<li>'.$e($k).': '.$e($n).'</li>')->implode('') ?: '<li>No tiene datos cargados.</li>';

        $shared = $preview['shared_users']
            ? '<p>'.$e($preview['shared_users']).' usuario(s) del portal también usan otra empresa: se conservan y solo pierden el acceso a esta.</p>'
            : '';

        $blockers = $preview['blockers']
            ? '<p style="color:#b91c1c;font-weight:600" data-erase-blockers>'.collect($preview['blockers'])->map($e)->implode('<br>').'</p>'
            : '';

        return new HtmlString(
            '<div data-erase-company="'.$e($record->id).'" style="text-align:left">'
            .'<ul>'.$who.'</ul>'
            .'<p style="margin-top:.5rem"><strong>Se borra para siempre</strong> (no es desactivar):</p><ul>'.$counts.'</ul>'
            .'<p>También sus archivos (fotos, videos, documentos y exportaciones). No toca otras empresas ni los datos globales.</p>'
            .$shared.$blockers.'</div>'
        );
    }
}
