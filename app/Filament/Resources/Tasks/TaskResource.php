<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Building;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Task;
use App\Models\WorkOrder;
use App\Services\Tasks\TaskService;
use App\Support\Access\Access;
use App\Support\Access\ActionGuard;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tareas y recordatorios (Etapa 6, PR 3): responsable, vencimiento,
 * prioridad, estado y recordatorio. Área «Tareas y recordatorios». Todo lo
 * que cambia pasa por TaskService (empresa, responsable, vínculos).
 */
class TaskResource extends Resource
{
    use GuardedByPermission;
    use ScopedToCurrentCompany;

    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Tareas';

    protected static ?string $modelLabel = 'tarea';

    protected static ?string $pluralModelLabel = 'tareas';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?string $slug = 'tareas';

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Tasks;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // se cancelan, no se borran
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Badge: vencidas abiertas. */
    public static function getNavigationBadge(): ?string
    {
        $count = Task::overdue()->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        $company = fn () => CompanyContext::currentId();

        return $schema->components([
            Section::make('Tarea')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('title')->label('Título')->required()->maxLength(160)->columnSpanFull(),
                Textarea::make('description')->label('Detalle')->rows(3)->maxLength(5000)->columnSpanFull(),
                Select::make('assigned_to')->label('Responsable')
                    ->options(fn () => app(TaskService::class)->assignableUsers((int) $company()))
                    ->placeholder('Sin responsable')->helperText('El dueño o un administrativo con acceso a tareas.'),
                Select::make('priority')->label('Prioridad')->options(Task::PRIORITIES)->default('medium')->required(),
                DateTimePicker::make('due_at')->label('Vence')->seconds(false),
                DateTimePicker::make('remind_at')->label('Recordatorio')->seconds(false)
                    ->helperText('Cuándo avisar (aparte del vencimiento). Se avisa una sola vez.'),
                Select::make('reminder_recipients')->label('Avisar a')->multiple()->searchable()
                    ->options(fn () => app(TaskService::class)->assignableUsers((int) $company()))
                    ->maxItems(TaskService::MAX_RECIPIENTS)
                    ->placeholder('El responsable (o quien la creó)')
                    ->helperText('El dueño o administrativos con acceso a tareas. Si no elegís a nadie, se avisa al responsable o a quien la creó.')
                    ->columnSpanFull(),
                Select::make('status')->label('Estado')->options(Task::STATUSES)->default('pending')->required()->visibleOn('edit'),
            ]),
            Section::make('Relacionada con (opcional)')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->collapsible()->schema([
                Select::make('client_id')->label('Cliente')->searchable()
                    ->options(fn () => Client::where('company_id', $company())->orderBy('name')->pluck('name', 'id')),
                Select::make('building_id')->label('Edificio')->searchable()
                    ->options(fn () => Building::where('company_id', $company())->orderBy('name')->pluck('name', 'id')),
                Select::make('elevator_id')->label('Ascensor')->searchable()
                    ->options(fn () => Elevator::where('company_id', $company())->with('building:id,name')->get()->mapWithKeys(fn ($e) => [$e->id => trim(($e->building?->name ?? '').' · '.$e->label)])),
                // Solo con presupuestos en el plan (el servidor también lo verifica en TaskService).
                Select::make('quote_id')->label('Presupuesto')->searchable()
                    ->visible(fn () => \App\Support\Plans\PlanGuard::currentAllows(\App\Enums\PlanFeature::Quotes))
                    ->options(fn () => Quote::where('company_id', $company())->latest()->limit(200)->get()->mapWithKeys(fn ($q) => [$q->id => $q->numberLabel().' · '.$q->title])),
                Select::make('work_order_id')->label('Orden de trabajo')->searchable()
                    ->options(fn () => WorkOrder::where('company_id', $company())->latest()->limit(200)->with('building:id,name')->get()->mapWithKeys(fn ($o) => [$o->id => '#'.$o->id.' · '.($o->building?->name ?? '')])),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['assignee:id,name', 'completer:id,name', 'client:id,name', 'building:id,name']))
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->label('Tarea')->searchable()->wrap()
                    ->description(fn (Task $record) => collect([$record->client?->name, $record->building?->name])->filter()->implode(' · ') ?: null),
                TextColumn::make('priority')->label('Prioridad')->badge()
                    ->formatStateUsing(fn ($state) => Task::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'urgent', 'high' => 'danger', 'medium' => 'warning', default => 'gray'
                    }),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state, Task $record) => $record->isOverdue() ? 'Vencida' : (Task::STATUSES[$state] ?? $state))
                    ->color(fn ($state, Task $record) => $record->isOverdue() ? 'danger' : match ($state) {
                        'done' => 'success', 'in_progress' => 'info', 'cancelled' => 'gray', default => 'warning'
                    })
                    ->description(fn (Task $record) => $record->status === 'done' && $record->completed_at ? 'Por '.($record->completer?->name ?? '—').' el '.$record->completed_at->format('d/m/Y H:i') : null),
                TextColumn::make('due_at')->label('Vence')->dateTime('d/m/Y H:i')->placeholder('Sin fecha')->sortable()
                    ->color(fn (Task $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('assignee.name')->label('Responsable')->placeholder('Sin responsable'),
            ])
            ->filters([
                SelectFilter::make('assigned_to')->label('Responsable')
                    ->options(fn () => app(TaskService::class)->assignableUsers((int) CompanyContext::currentId())),
                SelectFilter::make('priority')->label('Prioridad')->options(Task::PRIORITIES),
            ])
            ->recordActions([
                // Completar / reabrir: también el responsable con solo «ver» (lo verifica TaskService).
                Action::make('complete')->label('Completar')->icon('heroicon-o-check')->color('success')
                    ->extraAttributes(ActionGuard::viewing(), merge: true)
                    ->visible(fn (Task $record) => $record->isOpen() && self::canChangeStatus($record))
                    ->action(function (Task $record) {
                        app(TaskService::class)->setStatus(auth()->user(), $record, 'done');
                        Notification::make()->title('Tarea completada')->success()->send();
                    }),
                Action::make('reopen')->label('Reabrir')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->extraAttributes(ActionGuard::viewing(), merge: true)
                    ->visible(fn (Task $record) => ! $record->isOpen() && self::canChangeStatus($record))
                    ->action(fn (Task $record) => app(TaskService::class)->setStatus(auth()->user(), $record, 'pending')),
                EditAction::make(),
            ]);
    }

    public static function canChangeStatus(Task $task): bool
    {
        $user = auth()->user();

        return Access::can($user, PermissionArea::Tasks, PermissionArea::MANAGE)
            || ((int) $task->assigned_to === (int) $user?->id && Access::can($user, PermissionArea::Tasks));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
