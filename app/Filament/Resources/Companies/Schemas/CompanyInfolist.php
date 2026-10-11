<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;

class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('overview')->hiddenLabel()->columnSpanFull()->view('filament.companies.overview'),
        ]);
    }
}
