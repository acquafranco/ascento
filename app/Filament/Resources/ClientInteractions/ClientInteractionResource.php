<?php

namespace App\Filament\Resources\ClientInteractions;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\ClientInteractions\Pages\CreateClientInteraction;
use App\Filament\Resources\ClientInteractions\Pages\EditClientInteraction;
use App\Filament\Resources\ClientInteractions\Pages\ListClientInteractions;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de contactos con clientes (Etapa 6, PR 3). Área «Historial de
 * contactos». Complementa presupuestos y órdenes (los referencia, no los
 * copia). No se borra; las correcciones quedan en el registro de actividad.
 */
class ClientInteractionResource extends Resource
{
    use GuardedByPermission;
    use ScopedToCurrentCompany;

    protected static ?string $model = ClientInteraction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Historial de contactos';

    protected static ?string $modelLabel = 'contacto';

    protected static ?string $pluralModelLabel = 'contactos';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?string $slug = 'contactos';

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Contacts;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Campos del contacto (también en la pestaña del cliente, sin elegir cliente). */
    public static function fields(bool $withClient = true): array
    {
        $company = fn () => CompanyContext::currentId();
        $clientOf = fn (Get $get, $livewire) => $withClient ? $get('client_id') : $livewire->getOwnerRecord()->id;

        return array_values(array_filter([
            $withClient ? Select::make('client_id')->label('Cliente')->required()->searchable()->live()
                ->options(fn () => Client::where('company_id', $company())->orderBy('name')->pluck('name', 'id')) : null,
            DateTimePicker::make('occurred_at')->label('Fecha')->seconds(false)->required()->default(now()),
            Select::make('type')->label('Tipo')->options(ClientInteraction::TYPES)->required(),
            Select::make('result')->label('Resultado')->options(ClientInteraction::RESULTS)->required(),
            Select::make('building_id')->label('Edificio (opcional)')
                ->options(fn (Get $get, $livewire) => Building::where('company_id', $company())->where('client_id', $clientOf($get, $livewire))->orderBy('name')->pluck('name', 'id')),
            Select::make('quote_id')->label('Presupuesto relacionado (opcional)')->searchable()
                ->options(fn (Get $get, $livewire) => Quote::where('company_id', $company())->where('client_id', $clientOf($get, $livewire))->latest()->limit(100)->get()->mapWithKeys(fn ($q) => [$q->id => $q->numberLabel().' · '.$q->title])),
            Select::make('work_order_id')->label('Orden relacionada (opcional)')->searchable()
                ->options(fn (Get $get, $livewire) => WorkOrder::where('company_id', $company())->whereIn('building_id', Building::where('client_id', $clientOf($get, $livewire))->pluck('id'))->latest()->limit(100)->get()->mapWithKeys(fn ($o) => [$o->id => '#'.$o->id.' · '.($o->building?->name ?? '')])),
            Textarea::make('notes')->label('Observaciones')->rows(4)->maxLength(5000)->columnSpanFull(),
        ]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contacto')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema(self::fields()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client:id,name', 'building:id,name', 'quote:id,number,title,company_id']))
            ->defaultSort('occurred_at', 'desc')
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('client_id')->label('Cliente')->searchable()
                    ->options(fn () => Client::where('company_id', CompanyContext::currentId())->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('type')->label('Tipo')->options(ClientInteraction::TYPES),
                SelectFilter::make('result')->label('Resultado')->options(ClientInteraction::RESULTS),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function columns(bool $withClient = true): array
    {
        return array_values(array_filter([
            TextColumn::make('occurred_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
            $withClient ? TextColumn::make('client.name')->label('Cliente')->searchable() : null,
            TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state) => ClientInteraction::TYPES[$state] ?? $state)->badge()->color('gray'),
            TextColumn::make('result')->label('Resultado')->formatStateUsing(fn ($state) => ClientInteraction::RESULTS[$state] ?? $state)->badge()
                ->color(fn ($state) => match ($state) {
                    'resolved' => 'success', 'follow_up', 'awaiting_reply' => 'warning', default => 'gray'
                }),
            TextColumn::make('notes')->label('Observaciones')->limit(80)->wrap()
                ->description(fn (ClientInteraction $record) => collect([$record->building?->name, $record->quote ? 'Presupuesto '.$record->quote->numberLabel() : null])->filter()->implode(' · ') ?: null),
            TextColumn::make('user_name')->label('Registró'),
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientInteractions::route('/'),
            'create' => CreateClientInteraction::route('/create'),
            'edit' => EditClientInteraction::route('/{record}/edit'),
        ];
    }
}
