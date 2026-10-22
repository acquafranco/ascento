<?php

namespace App\Filament\Resources\SalesLeads;

use App\Filament\Resources\SalesLeads\Pages\ListSalesLeads;
use App\Filament\Resources\SalesLeads\Pages\ViewSalesLead;
use App\Models\SalesLead;
use App\Models\SubscriptionPlan;
use App\Services\Activity\PlatformAudit;
use App\Services\Sales\CalculatorInput;
use App\Services\Sales\ImplementationQuote;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Pedidos comerciales de la calculadora (implementación o propuesta
 * personalizada). Solo el SuperAdmin.
 */
class SalesLeadResource extends Resource
{
    protected static ?string $model = SalesLead::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Pedidos comerciales';

    protected static ?string $modelLabel = 'pedido comercial';

    protected static ?string $pluralModelLabel = 'Pedidos comerciales';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'pedidos-comerciales';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $new = static::canAccess() ? SalesLead::where('status', 'new')->count() : 0;

        return $new ? (string) $new : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state) => SalesLead::TYPES[$state] ?? $state)->badge(),
                TextColumn::make('name')->label('Contacto')->description(fn (SalesLead $r) => $r->company_name)->searchable(['name', 'company_name', 'email']),
                TextColumn::make('size')->label('Tamaño')->state(fn (SalesLead $r) => ($r->inputs['edificios'] ?? '?').' edif. · '.($r->inputs['ascensores'] ?? '?').' asc. · '.($r->inputs['clientes'] ?? '?').' clientes'),
                TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => SalesLead::STATUSES[$state] ?? $state)->badge()
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'contacted' => 'info', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(SalesLead::STATUSES),
                SelectFilter::make('type')->label('Tipo')->options(SalesLead::TYPES),
            ])
            ->recordUrl(fn (SalesLead $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('type')->label('Tipo')->formatStateUsing(fn ($state) => SalesLead::TYPES[$state] ?? $state),
                TextEntry::make('status')->label('Estado')->formatStateUsing(fn ($state) => SalesLead::STATUSES[$state] ?? $state)->badge(),
                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('email')->label('Correo')->copyable(),
                TextEntry::make('phone')->label('Teléfono')->placeholder('—'),
                TextEntry::make('company_name')->label('Empresa')->placeholder('—'),
                TextEntry::make('plan_slug')->label('Plan sugerido')->formatStateUsing(fn ($state) => SubscriptionPlan::findBySlug($state)?->shortName() ?? '—')->placeholder('Ninguno alcanza'),
                TextEntry::make('message')->label('Mensaje')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Datos que ingresó')->columns(['default' => 2, 'md' => 4])->columnSpanFull()->schema([
                TextEntry::make('inputs.edificios')->label('Edificios'),
                TextEntry::make('inputs.ascensores')->label('Ascensores y montacargas'),
                TextEntry::make('inputs.clientes')->label('Clientes'),
                TextEntry::make('inputs.tecnicos')->label('Técnicos'),
                TextEntry::make('data_state')->label('Estado de los datos')->state(fn (SalesLead $r) => CalculatorInput::DATA_STATES[$r->inputs['datos'] ?? 'tidy'] ?? '—'),
                TextEntry::make('options')->label('Opcionales')->state(fn (SalesLead $r) => collect($r->inputs['opcionales'] ?? [])->map(fn ($o) => CalculatorInput::OPTIONS[$o] ?? $o)->implode(', ') ?: 'Ninguno'),
                TextEntry::make('quote_seen')->label('Lo que vio')->columnSpan(2)
                    ->state(fn (SalesLead $r) => match ($r->quote['status'] ?? 'pending') {
                        'priced' => 'Total inicial '.ImplementationQuote::format($r->quote['total'] ?? $r->quote['implementation'] ?? null),
                        'custom' => 'Propuesta personalizada (superó el tope)',
                        default => 'Tarifas sin publicar: «a confirmar»',
                    }),
            ]),
        ]);
    }

    public static function statusAction(): Action
    {
        return Action::make('status')->label('Cambiar estado')->icon('heroicon-o-arrow-path')
            ->visible(fn () => static::canAccess())
            ->fillForm(fn (SalesLead $record) => ['status' => $record->status])
            ->schema([Select::make('status')->label('Estado')->options(SalesLead::STATUSES)->required()->native(false)])
            ->action(function (SalesLead $record, array $data) {
                abort_unless(static::canAccess(), 403);
                $record->forceFill(['status' => $data['status'], 'handled_by' => auth()->id()])->save();
                PlatformAudit::record('sales_lead.status', $record, ['status' => $data['status']], 'Pedido comercial #'.$record->id);
                Notification::make()->title('Estado actualizado')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesLeads::route('/'),
            'view' => ViewSalesLead::route('/{record}'),
        ];
    }
}
