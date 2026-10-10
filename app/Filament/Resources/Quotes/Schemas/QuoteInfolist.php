<?php

namespace App\Filament\Resources\Quotes\Schemas;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Vista del presupuesto en el panel: el mismo documento que recibe el cliente
 * (resources/views/quotes/document.blade.php) y al costado los datos de
 * gestión: envío, cobro, notas internas e historial.
 */
class QuoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'xl' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Section::make()
                            ->columnSpan(['default' => 1, 'xl' => 2])
                            ->schema([
                                ViewEntry::make('document')->hiddenLabel()->view('filament.quotes.document'),
                            ]),
                        Section::make('Gestión')
                            ->columnSpan(1)
                            ->schema([
                                ViewEntry::make('management')->hiddenLabel()->view('filament.quotes.management'),
                            ]),
                    ]),
            ]);
    }
}
