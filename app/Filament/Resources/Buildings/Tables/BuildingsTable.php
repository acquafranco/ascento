<?php

namespace App\Filament\Resources\Buildings\Tables;

use App\Models\User;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use App\Services\Buildings\BulkAssignmentService;
use App\Services\Visits\VisitPlanService;
use App\Support\CompanyContext;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BuildingsTable
{
    public static function configure(
        Table $table
    ): Table
    {
        return $table
            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['client', 'users', 'visitPlans']))
            ->columns([

                TextColumn::make('name')
                    ->label('Edificio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address')
                    ->label('Dirección')
                    ->searchable(),

                TextColumn::make('locality')
                    ->label('Localidad')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('neighborhood')
                    ->label('Barrio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable(),

                TextColumn::make('map_color')
                    ->label('Color')
                    ->state(fn ($record) => $record->mapColorKey())
                    ->formatStateUsing(fn ($state) => \App\Models\Building::MAP_COLORS[$state][0] ?? '')
                    ->icon('heroicon-s-map-pin')
                    ->iconColor(fn ($record) => \Filament\Support\Colors\Color::hex($record->mapColorHex()))
                    ->toggleable(),

                TextColumn::make('elevator_count')
                    ->label('Asc.')
                    ->sortable(),

                TextColumn::make('freight_elevator_count')
                    ->label('Mont.')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('technicians')
                ->label('Técnico')
                ->state(function ($record) {

                    if ($record->users->isEmpty()) {
                        return 'Sin asignar';
                    }

                    return $record->users
                        ->map(function ($user) {

                            $tipo = match ($user->pivot->type) {
                                'maintenance' => 'Mantenimiento',
                                'inspection' => 'Inspección',
                                default => '',
                            };

                            return "{$user->name} ({$tipo})";
                        })
                        ->implode(', ');
                })
                ->badge()
                ->color('success'),

                // Frecuencia de VISITAS (no la de cobro del servicio).
                TextColumn::make('visit_frequency')
                    ->label('Frecuencia de visitas')
                    ->state(fn ($record) => 'Mant.: '.$record->visitFrequencyLabel('maintenance').' · Insp.: '.$record->visitFrequencyLabel('inspection'))
                    ->size('sm')
                    ->color('gray')
                    ->toggleable(),
            ])

            ->recordActions([
                RestoreAction::make()->before(\App\Support\Plans\PlanRestoreGuard::before(\App\Enums\PlanLimit::Buildings))->label('Reactivar'),

                EditAction::make(),

                /*
                |--------------------------------------------------------------------------
                | ASIGNAR EMPLEADO
                |--------------------------------------------------------------------------
                */

                Action::make('assignTechnician')
                    ->label('Asignar empleado')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')

                    ->form([

                        Select::make('user_ids')
                            ->label('Empleados')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->options(function () {
                                $user = auth()->user();

                                $companyId = $user->isSuperAdmin()
                                    ? session('selected_company_id')
                                    : $user->company_id;

                                return User::query()
                                    ->where('company_id', $companyId)
                                    ->whereNotIn('role', ['admin', 'client']) // solo técnicos
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }),

                                                Select::make('type')
                                                    ->label('Trabajo')
                                                    ->options([
                                                        'maintenance'
                                                            => 'Mantenimiento',

                                                        'inspection'
                                                            => 'Inspección',
                                                    ])
                                                    ->required(),

                                            ])

                                        ->action(function (array $data, $record) {

                            /*
                            |--------------------------------------------------------------------------
                            | INSPECCIÓN
                            |--------------------------------------------------------------------------
                            */

                            if ($data['type'] === 'inspection') {

                                if (count($data['user_ids']) > 1) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Solo puede existir un inspector por edificio.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                if (
                                    $record->users()
                                        ->wherePivot('type', 'inspection')
                                        ->exists()
                                ) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Este edificio ya tiene un inspector asignado.')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | MANTENIMIENTO
                            |--------------------------------------------------------------------------
                            */

                            if ($data['type'] === 'maintenance') {

                                if (count($data['user_ids']) > 2) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Solo pueden asignarse hasta dos técnicos de mantenimiento.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $actuales = $record->users()
                                    ->wherePivot('type', 'maintenance')
                                    ->count();

                                if ($actuales + count($data['user_ids']) > 2) {

                                    \Filament\Notifications\Notification::make()
                                        ->title('Este edificio ya tiene el máximo de técnicos de mantenimiento.')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | ASIGNAR
                            |--------------------------------------------------------------------------
                            */

                            foreach ($data['user_ids'] as $userId) {

                                $existe = $record->users()
                                    ->where('users.id', $userId)
                                    ->wherePivot('type', $data['type'])
                                    ->exists();

                                if (! $existe) {

                                    $record->users()->attach(
                                        $userId,
                                        [
                                            'type' => $data['type'],
                                        ]
                                    );

                                    app(\App\Services\Notifications\AssignmentNotifier::class)->building($record, (int) $userId, $data['type'], true);

                                }

                            }

                                    \Filament\Notifications\Notification::make()
                                        ->title('Empleados asignados correctamente.')
                                        ->success()
                                        ->send();

                                }),
                /*
                |--------------------------------------------------------------------------
                | FRECUENCIA DE VISITAS
                |--------------------------------------------------------------------------
                | Cada cuánto se visita el edificio (mantenimiento e inspección por
                | separado). Rige desde el mes elegido: el historial no cambia.
                */
                Action::make('visitFrequency')
                    ->label('Frecuencia de visitas')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->hidden(fn ($record) => $record->trashed())
                    ->modalHeading(fn ($record) => 'Frecuencia de visitas · '.$record->name)
                    ->modalDescription(fn ($record) => 'Actual: mantenimiento '.mb_strtolower($record->visitFrequencyLabel('maintenance')).', inspección '.mb_strtolower($record->visitFrequencyLabel('inspection')).'. Es cada cuánto se visita, no cada cuánto se cobra el servicio.')
                    ->modalSubmitActionLabel('Guardar frecuencia')
                    ->form(self::frequencyFields(withKeep: false))
                    ->action(function (array $data, $record) {
                        [$perMonth, $every, $start] = VisitPlanService::normalize($data['frequency'], $data['every_months'] ?? null, $data['starts_on'] ?? null);
                        $changed = app(VisitPlanService::class)->set($record, $data['type'], $perMonth, $every, $start);

                        Notification::make()
                            ->title($changed ? 'Frecuencia guardada' : 'Sin cambios: ya tenía esa frecuencia')
                            ->body(\App\Models\BuildingVisitPlan::TYPES[$data['type']].': '.\App\Models\BuildingVisitPlan::labelFor($perMonth, $every).' desde '.mb_strtolower(VisitPlanService::startOptions()[$data['starts_on']]).'.')
                            ->success()
                            ->send();
                    }),

                                                /*
                                                |--------------------------------------------------------------------------
                                                | QUITAR EMPLEADO
                                                |--------------------------------------------------------------------------
                                                */

                                                Action::make('removeTechnician')
                                                    ->label('Quitar asignación')
                                                    ->icon('heroicon-o-user-minus')
                                                    ->color('danger')

                                                    ->form([

                                    Select::make('assignment')
                                        ->label('Asignación')
                                        ->searchable()
                                        ->required()
                                        ->options(function ($record) {

                                            return $record->users
                                                ->mapWithKeys(function ($user) {

                                                    return [

                                                        $user->id.'-'.$user->pivot->type =>

                                                            $user->name.' • '.

                                                            (
                                                                $user->pivot->type === 'maintenance'
                                                                    ? 'Mantenimiento'
                                                                    : 'Inspección'
                                                            ),

                                                    ];

                                                });

                                        }),

                                ])

                                        ->action(function (array $data, $record) {

                            [$userId, $type] = explode('-', $data['assignment']);

                            $removed = $record->users()
                                ->wherePivot('type', $type)
                                ->detach($userId);

                            if ($removed) {
                                app(\App\Services\Notifications\AssignmentNotifier::class)->building($record, (int) $userId, $type, false);
                            }

                        })

                    ->successNotificationTitle(
                        'Asignación eliminada'
                    ),
            ])
                ->filters([
                TrashedFilter::make()->label('Desactivados'),
                    SelectFilter::make('locality')
                        ->label('Localidad')
                        ->options(function () {
                            $user = auth()->user();

                            $companyId = $user->isSuperAdmin()
                                ? session('selected_company_id')
                                : $user->company_id;

                            return \App\Models\Building::query()
                                ->where('company_id', $companyId)
                                ->whereNotNull('locality')
                                ->pluck('locality', 'locality')
                                ->toArray();
                        })
                ])
            ->toolbarActions([

                BulkActionGroup::make([
                    self::bulkAssignAction(),
                    DeleteBulkAction::make()
                        ->label('Desactivar seleccionados')
                        ->modalHeading('Desactivar seleccionados')
                        ->modalSubmitActionLabel('Desactivar'),
                    RestoreBulkAction::make()->before(\App\Support\Plans\PlanRestoreGuard::beforeBulk(\App\Enums\PlanLimit::Buildings))->label('Reactivar seleccionados'),
                ]),

            ]);
    }

    /**
     * Asignación masiva: elegir tipo, técnicos, modo (agregar, reemplazar o
     * conservar) y, si se quiere, la frecuencia; después un resumen de lo que
     * va a pasar en cada edificio; al confirmar, el servidor revalida todo y
     * aplica en una sola transacción (BulkAssignmentService).
     */
    private static function bulkAssignAction(): BulkAction
    {
        return BulkAction::make('bulkAssign')
            ->label('Asignar técnicos')
            ->icon('heroicon-o-user-group')
            ->color('success')
            ->modalHeading('Asignar técnicos a los edificios seleccionados')
            ->modalSubmitActionLabel('Confirmar asignación')
            ->deselectRecordsAfterCompletion()
            ->steps(fn (Collection $records) => [
                Step::make('Asignación')
                    ->description($records->count().' '.($records->count() === 1 ? 'edificio seleccionado' : 'edificios seleccionados'))
                    ->schema([
                        Select::make('type')
                            ->label('Trabajo')
                            ->options(\App\Models\BuildingVisitPlan::TYPES)
                            ->default('maintenance')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('user_ids', [])),
                        Select::make('user_ids')
                            ->label('Técnicos')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn () => User::query()
                                ->where('company_id', CompanyContext::currentId())
                                ->where('role', 'technician')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->maxItems(fn (Get $get) => BulkAssignmentService::LIMITS[$get('type')] ?? 2)
                            ->helperText(fn (Get $get) => $get('type') === 'inspection'
                                ? 'Un inspector por edificio. Dejalo vacío si solo querés cambiar la frecuencia.'
                                : 'Hasta dos técnicos de mantenimiento por edificio. Dejalo vacío si solo querés cambiar la frecuencia.'),
                        Radio::make('mode')
                            ->label('Si el edificio ya tiene técnicos de ese trabajo')
                            ->options(BulkAssignmentService::MODES)
                            ->default('add')
                            ->required(),
                        ...self::frequencyFields(withKeep: true, withType: false),
                    ]),
                Step::make('Revisar')
                    ->description('Qué va a cambiar antes de confirmar')
                    ->schema([
                        View::make('filament.buildings.bulk-assignment-preview')
                            ->viewData(function (Get $get) use ($records) {
                                try {
                                    return ['plan' => app(BulkAssignmentService::class)->plan((int) CompanyContext::currentId(), $records->pluck('id'), [
                                        'type' => $get('type'), 'user_ids' => $get('user_ids') ?? [], 'mode' => $get('mode'),
                                        'frequency' => $get('frequency'), 'every_months' => $get('every_months'), 'starts_on' => $get('starts_on'),
                                    ])];
                                } catch (ValidationException $e) {
                                    return ['error' => collect($e->errors())->flatten()->first()];
                                }
                            }),
                    ]),
            ])
            ->action(function (array $data, Collection $records) {
                try {
                    $result = app(BulkAssignmentService::class)->apply((int) CompanyContext::currentId(), $records->pluck('id'), $data);
                } catch (ValidationException $e) {
                    Notification::make()->title('No se aplicó la asignación')->body(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                $skipped = collect($result['rows'])->where('outcome', 'skipped');

                Notification::make()
                    ->title('Asignación aplicada')
                    ->body(collect([
                        $result['counts']['change'].' con cambios de técnicos',
                        $result['counts']['unchanged'].' sin cambios de técnicos',
                        $result['frequency'] ? 'frecuencia: '.$result['frequency']['label'] : null,
                        $skipped->isNotEmpty() ? $skipped->count().' salteados ('.$skipped->take(5)->map(fn ($r) => $r['name'].': '.$r['reason'])->implode('; ').($skipped->count() > 5 ? '…' : '').')' : null,
                    ])->filter()->implode(' · ').'.')
                    ->color($skipped->isNotEmpty() ? 'warning' : 'success')
                    ->icon($skipped->isNotEmpty() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                    ->persistent($skipped->isNotEmpty())
                    ->send();
            });
    }

    /** Campos de frecuencia de visitas (acción individual y masiva). */
    private static function frequencyFields(bool $withKeep, bool $withType = true): array
    {
        $options = collect(\App\Models\BuildingVisitPlan::FREQUENCIES)->map(fn ($f) => $f[0])->all();

        return array_values(array_filter([
            $withType ? Select::make('type')
                ->label('Trabajo')
                ->options(\App\Models\BuildingVisitPlan::TYPES)
                ->default('maintenance')
                ->required() : null,
            Select::make('frequency')
                ->label('Frecuencia de visitas')
                ->options($withKeep ? ['keep' => 'No cambiar'] + $options : $options)
                ->default($withKeep ? 'keep' : 'monthly')
                ->required()
                ->live()
                ->helperText('Cada cuánto se visita el edificio. No cambia la frecuencia de cobro del servicio.'),
            Select::make('every_months')
                ->label('Cada cuántos meses')
                ->options(collect(range(2, 12))->mapWithKeys(fn ($n) => [$n => \App\Models\BuildingVisitPlan::labelFor(1, $n)])->all())
                ->default(3)
                ->visible(fn (Get $get) => $get('frequency') === 'months')
                ->required(fn (Get $get) => $get('frequency') === 'months'),
            Select::make('starts_on')
                ->label('Desde')
                ->options(VisitPlanService::startOptions())
                ->default(now()->format('Y-m'))
                ->visible(fn (Get $get) => filled($get('frequency')) && $get('frequency') !== 'keep')
                ->required(fn (Get $get) => filled($get('frequency')) && $get('frequency') !== 'keep')
                ->helperText('Los meses anteriores conservan la frecuencia que tenían. Para frecuencias de varios meses, los períodos se cuentan desde este mes.'),
        ]));
    }
}
