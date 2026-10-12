<?php

namespace App\Filament\Resources\PhotoSections\Pages;

use App\Filament\Resources\PhotoSections\PhotoSectionResource;
use App\Models\PhotoSection;
use App\Support\CompanyContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePhotoSections extends ManageRecords
{
    protected static string $resource = PhotoSectionResource::class;

    protected ?string $subheading = 'Las secciones que el técnico ve para cargar fotos de antes y después en mantenimientos e inspecciones. Desactivá las que no uses: las fotos ya cargadas se conservan.';

    public function mount(): void
    {
        parent::mount();

        // La primera vez, las secciones iniciales (Pozo, Techo, Sala de máquinas).
        if ($companyId = CompanyContext::currentId()) {
            PhotoSection::forCompany($companyId);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva sección'),
        ];
    }
}
