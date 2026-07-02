<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\Schemas\QuoteForm;
use App\Filament\Resources\Quotes\Schemas\QuoteInfolist;
use App\Filament\Resources\Quotes\Tables\QuotesTable;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';
    // 📌 Icono del menú
    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedDocumentText;

    // 📌 nombres UI
    protected static ?string $navigationLabel = 'Presupuestos';
    protected static ?string $modelLabel = 'Presupuesto';
    protected static ?string $pluralModelLabel = 'Presupuestos';

    // 📌 importante para búsquedas globales
    protected static ?string $recordTitleAttribute = 'title';

    // 🧱 FORM
    public static function form(Schema $schema): Schema
    {
        return QuoteForm::configure($schema);
    }

    // 📊 INFO VIEW (detalle)
    public static function infolist(Schema $schema): Schema
    {
        return QuoteInfolist::configure($schema);
    }

    // 📋 TABLE
    public static function table(Table $table): Table
    {
        return QuotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    // 🚀 PAGES
    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
