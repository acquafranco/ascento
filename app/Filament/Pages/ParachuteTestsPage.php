<?php

namespace App\Filament\Pages;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use App\Models\Client;
use App\Models\Elevator;
use App\Models\ParachuteSchedule;
use App\Services\Parachute\ParachuteService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Pruebas de paracaídas: el estado de cada ascensor (vencidas, próximas, no
 * satisfactorias, sin registro, sin periodicidad, al día), su política
 * (periodicidad, aviso, inspector y responsable) y los avisos enviados.
 * Área «Inspecciones y reportes»: ver; configurar pide «gestionar» (lo
 * verifica ParachuteService).
 */
class ParachuteTestsPage extends Page
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ParachuteTests;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Inspections;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Pruebas de paracaídas';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'paracaidas';

    protected static ?string $title = 'Pruebas de paracaídas';

    protected string $view = 'filament.pages.parachute-tests';

    #[Url(as: 'estado')]
    public ?string $status = null;

    public static function canAccess(): bool
    {
        return Access::can(auth()->user(), PermissionArea::Inspections);
    }

    public function getSubheading(): ?string
    {
        return 'Una prueba prevista es la próxima fecha calculada; realizada es la que se registró. Un aviso nunca cuenta como prueba hecha. Ascento no asume una periodicidad: se define por ascensor o por cliente.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')->label('Historial')->color('gray')->icon('heroicon-o-clock')->url(ParachuteTestResource::getUrl('index')),
            $this->clientPolicyAction()->visible(fn () => Access::can(auth()->user(), PermissionArea::Inspections, PermissionArea::MANAGE)),
            Action::make('record')->label('Registrar prueba')->icon('heroicon-o-plus')->url(ParachuteTestResource::getUrl('create'))
                ->visible(fn () => ParachuteTestResource::canCreate()),
        ];
    }

    private function elevatorOf(array $arguments): Elevator
    {
        // Scope de empresa: un ascensor de otra empresa → 404.
        return Elevator::query()->findOrFail((int) ($arguments['elevator'] ?? 0));
    }

    public function configureAction(): Action
    {
        return Action::make('configure')
            ->label('Configurar')
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => 'Prueba de paracaídas · '.$this->elevatorOf($arguments)->label)
            ->modalDescription('Si dejás la periodicidad vacía se usa la del cliente; si tampoco tiene, el ascensor queda «sin periodicidad definida».')
            ->fillForm(function (array $arguments) {
                $schedule = ParachuteSchedule::where('elevator_id', $this->elevatorOf($arguments)->id)->first();

                return [
                    'interval_months' => $schedule?->interval_months,
                    'notice_days' => $schedule?->notice_days ?? ParachuteSchedule::DEFAULT_NOTICE_DAYS,
                    'inspector_id' => $schedule?->inspector_id,
                    'responsible_id' => $schedule?->responsible_id,
                ];
            })
            ->schema([
                TextInput::make('interval_months')->label('Periodicidad (meses)')->numeric()->minValue(1)->maxValue(ParachuteService::MAX_INTERVAL_MONTHS)
                    ->helperText('Según la política aplicable a este equipo.'),
                TextInput::make('notice_days')->label('Avisar con (días de anticipación)')->numeric()->minValue(0)->maxValue(ParachuteService::MAX_NOTICE_DAYS)->required(),
                Select::make('inspector_id')->label('Inspector asignado')->options(fn () => app(ParachuteService::class)->people((int) CompanyContext::currentId()))->searchable()->placeholder('Sin asignar'),
                Select::make('responsible_id')->label('Responsable técnico')->options(fn () => app(ParachuteService::class)->people((int) CompanyContext::currentId()))->searchable()->placeholder('Sin asignar'),
            ])
            ->action(function (array $data, array $arguments) {
                app(ParachuteService::class)->configure(auth()->user(), $this->elevatorOf($arguments), $data);
                Notification::make()->title('Guardado')->success()->send();
            });
    }

    public function clientPolicyAction(): Action
    {
        return Action::make('clientPolicy')
            ->label('Periodicidad por cliente')
            ->icon('heroicon-o-building-office')
            ->color('gray')
            ->modalDescription('Se aplica a los ascensores del cliente que no tienen una periodicidad propia. Vacío: sin definir.')
            ->schema([
                Select::make('client_id')->label('Cliente')->options(fn () => Client::where('company_id', CompanyContext::currentId())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()->live()
                    ->afterStateUpdated(fn ($state, $set) => $set('months', Client::find($state)?->parachute_interval_months)),
                TextInput::make('months')->label('Periodicidad (meses)')->numeric()->minValue(1)->maxValue(ParachuteService::MAX_INTERVAL_MONTHS),
            ])
            ->action(function (array $data) {
                app(ParachuteService::class)->setClientInterval(auth()->user(), Client::query()->findOrFail((int) $data['client_id']), $data['months'] ?? null);
                Notification::make()->title('Guardado')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        $rows = app(ParachuteService::class)->overview((int) CompanyContext::currentId());
        $order = array_keys(ParachuteService::STATUSES);
        $groups = $rows->groupBy('status')->sortBy(fn ($group, $key) => array_search($key, $order, true));

        return [
            'groups' => $this->status && isset(ParachuteService::STATUSES[$this->status]) ? $groups->only([$this->status]) : $groups,
            'counts' => $rows->countBy('status'),
            'total' => $rows->count(),
            'canCreate' => ParachuteTestResource::canCreate(),
            'canManage' => Access::can(auth()->user(), PermissionArea::Inspections, PermissionArea::MANAGE),
            'reminders' => app(ParachuteService::class)->reminderSummary((int) CompanyContext::currentId()),
        ];
    }
}
