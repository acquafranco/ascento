<?php

namespace App\Filament\Pages;

use App\Enums\PermissionArea;
use App\Models\Building;
use App\Models\BuildingPublicPage;
use App\Models\Elevator;
use App\Services\PublicQr\PublicBuildingInfo;
use App\Services\PublicQr\PublicQrService;
use App\Support\Access\Access;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * QR públicos de los edificios (Etapa 7). Sin edificio elegido: el listado
 * con el estado de cada QR. Con uno: generar, descargar, desactivar,
 * regenerar, qué se publica, el estado informado de cada ascensor y la
 * vista previa de lo que verá quien escanee. Ver: «QR y datos públicos»;
 * todo lo que cambia algo pide «gestionar» (lo verifica PublicQrService).
 */
class PublicQrPage extends Page
{
    /** Área de permisos de administrativos. */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::PublicPages;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?string $navigationLabel = 'QR públicos';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'qr-publicos';

    protected static ?string $title = 'QR públicos';

    protected string $view = 'filament.pages.public-qr';

    #[Url(as: 'edificio')]
    public ?int $buildingId = null;

    /** @var array<string, bool> */
    public array $sections = [];

    /** @var array<int, ?string> ascensor => estado informado */
    public array $statuses = [];

    public static function canAccess(): bool
    {
        return Access::can(auth()->user(), PermissionArea::PublicPages);
    }

    public function mount(): void
    {
        if ($this->buildingId !== null) {
            $this->loadBuilding();
        }
    }

    /** Edificio de la empresa actual (scope global): otro → 404. */
    public function building(): ?Building
    {
        if ($this->buildingId === null) {
            return null;
        }

        return Building::query()->findOrFail($this->buildingId);
    }

    private function loadBuilding(): void
    {
        $building = $this->building();
        $page = app(PublicQrService::class)->pageOf($building);
        $current = $page ?? $this->draft($building);

        $this->sections = collect(BuildingPublicPage::SECTIONS)->mapWithKeys(fn ($label, $key) => [$key => (bool) $current->{$key}])->all();
        $this->statuses = $this->elevators($building)->mapWithKeys(fn (Elevator $e) => [$e->id => $e->public_status])->all();
    }

    private function elevators(Building $building)
    {
        return Elevator::where('building_id', $building->id)->where('is_active', true)->orderBy('label')->get();
    }

    /** Lo que se publicaría si se genera ahora (los valores por defecto de la tabla). */
    private function draft(Building $building): BuildingPublicPage
    {
        $draft = new BuildingPublicPage;
        $draft->forceFill([
            'company_id' => $building->company_id, 'building_id' => $building->id, 'is_active' => false,
            'show_elevators' => true, 'show_status' => false, 'show_last_maintenance' => true, 'show_last_inspection' => true,
            'show_safety_tests' => false, 'show_pending_work' => false, 'show_contact' => false,
        ]);

        return $draft;
    }

    public function getTitle(): string
    {
        return $this->buildingId ? 'QR público · '.($this->building()?->name ?? '') : 'QR públicos';
    }

    public function getSubheading(): ?string
    {
        return $this->buildingId
            ? 'Quien escanee el código ve solo lo que elijas acá, sin cuenta y sin poder modificar nada. No es una certificación de seguridad.'
            : 'Un código por edificio para que residentes y visitantes consulten información del ascensor.';
    }

    /* ----------------------------------------------------------- ACCIONES */

    /** Primer QR (o uno nuevo después de desactivarlo): sin confirmación, no hay un código vigente que se pierda. */
    public function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generar QR')
            ->icon('heroicon-o-qr-code')
            ->visible(fn () => ! $this->currentPage()?->is_active)
            ->action(fn () => $this->issue('QR listo'));
    }

    /** Código nuevo: el impreso deja de funcionar, por eso se confirma. */
    public function regenerateAction(): Action
    {
        return Action::make('regenerate')
            ->label('Regenerar QR')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn () => (bool) $this->currentPage()?->is_active)
            ->requiresConfirmation()
            ->modalHeading('Regenerar el QR')
            ->modalDescription('Se crea un código nuevo y el anterior deja de funcionar al instante. Vas a tener que reemplazar el impreso.')
            ->modalSubmitActionLabel('Regenerar')
            ->action(fn () => $this->issue('QR regenerado'));
    }

    private function issue(string $title): void
    {
        $service = app(PublicQrService::class);
        $page = $service->generate(auth()->user(), $this->building());
        $service->updateSections(auth()->user(), $page, $this->sections);
        Notification::make()->title($title)->body('Descargalo e imprimilo para el edificio.')->success()->send();
    }

    public function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Desactivar')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn () => (bool) $this->currentPage()?->is_active)
            ->requiresConfirmation()
            ->modalHeading('Desactivar el QR')
            ->modalDescription('El código deja de mostrar información al instante. Si después generás uno nuevo, el impreso anterior no vuelve a funcionar.')
            ->modalSubmitActionLabel('Desactivar')
            ->action(function () {
                app(PublicQrService::class)->deactivate(auth()->user(), $this->currentPage());
                Notification::make()->title('QR desactivado')->success()->send();
            });
    }

    public function saveSettingsAction(): Action
    {
        return Action::make('saveSettings')
            ->label('Guardar')
            ->icon('heroicon-o-check')
            ->action(function () {
                $user = auth()->user();
                $service = app(PublicQrService::class);
                $building = $this->building();

                if ($page = $service->pageOf($building)) {
                    $service->updateSections($user, $page, $this->sections);
                }

                $elevators = $this->elevators($building)->keyBy('id');
                foreach ($this->statuses as $id => $status) {
                    if ($elevator = $elevators->get((int) $id)) {
                        $service->setElevatorStatus($user, $elevator, $status ?: null);
                    }
                }

                Notification::make()->title('Guardado')->body('La vista pública ya muestra estos cambios.')->success()->send();
            });
    }

    private function currentPage(): ?BuildingPublicPage
    {
        $building = $this->building();

        return $building ? app(PublicQrService::class)->pageOf($building) : null;
    }

    protected function getViewData(): array
    {
        if ($this->buildingId === null) {
            $buildings = Building::query()->orderBy('name')->get(['id', 'name', 'address', 'locality', 'company_id']);
            $pages = BuildingPublicPage::query()->whereIn('building_id', $buildings->pluck('id'))->get()->keyBy('building_id');

            return ['buildings' => $buildings, 'pages' => $pages, 'building' => null];
        }

        $building = $this->building();
        $page = $this->currentPage();
        // Vista previa con lo elegido en pantalla (todavía sin guardar).
        $preview = ($page ?? $this->draft($building))->replicate()->forceFill(array_map('boolval', $this->sections));
        $preview->updated_at = $page?->updated_at ?? now();

        return [
            'building' => $building,
            'page' => $page,
            'qr' => $page?->is_active ? app(PublicQrService::class)->image($page) : null,
            'info' => app(PublicBuildingInfo::class)->for($preview),
            'elevators' => $this->elevators($building),
            'canManage' => Access::can(auth()->user(), PermissionArea::PublicPages, PermissionArea::MANAGE),
        ];
    }
}
