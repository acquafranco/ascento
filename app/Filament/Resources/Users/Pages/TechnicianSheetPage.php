<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\Technicians\TechnicianSheet;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Planilla de un técnico en el panel: la misma que ve él en la app, para el
 * dueño de la empresa (y el SuperAdmin con esa empresa elegida). Nadie más:
 * ni otro técnico, ni un administrativo, ni otra empresa (404).
 */
class TechnicianSheetPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.users.technician-sheet';

    #[Url]
    public ?int $month = null;

    #[Url]
    public ?int $year = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(TechnicianSheet::canView(auth()->user(), $this->record), 404);

        $this->month = ($this->month >= 1 && $this->month <= 12) ? $this->month : now()->month;
        $this->year = ($this->year >= 2000 && $this->year <= 2100) ? $this->year : now()->year;
    }

    public function getTitle(): string
    {
        return 'Planilla de '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Sus trabajos del mes, día por día. Solo la ven el técnico y el dueño de la empresa.';
    }

    public function getBreadcrumb(): string
    {
        return 'Planilla';
    }

    protected function getViewData(): array
    {
        /** @var User $technician */
        $technician = $this->record;
        abort_unless(TechnicianSheet::canView(auth()->user(), $technician), 404);

        return [
            'sheet' => app(TechnicianSheet::class)->month($technician, (int) $this->month, (int) $this->year),
            'navUrl' => fn (int $m, int $y) => static::getUrl(['record' => $technician, 'month' => $m, 'year' => $y]),
            'remitoUrl' => fn (DeliveryNote $note) => DeliveryNoteResource::canView($note) ? DeliveryNoteResource::getUrl('view', ['record' => $note]) : null,
        ];
    }
}
