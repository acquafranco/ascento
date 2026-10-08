<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Cobros de la suscripción (cuotas de Mercado Pago). Solo lectura.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Cobros';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Todavía no hay cobros')
            ->columns([
                TextColumn::make('paid_at')->label('Fecha')->date('d/m/Y')->placeholder(fn ($record) => $record->created_at->format('d/m/Y')),
                TextColumn::make('amount')
                    ->label('Importe')
                    ->formatStateUsing(fn ($state, $record) => $state ? '$'.number_format((float) $state, 0, ',', '.').' '.$record->currency : '—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'approved' => 'Aprobado', 'rejected' => 'Rechazado', 'amount_mismatch' => 'Importe distinto (revisar)', default => 'Pendiente'
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'rejected', 'amount_mismatch' => 'danger', default => 'warning'
                    }),
                TextColumn::make('status_detail')
                    ->label('Detalle')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'accredited' => 'Acreditado',
                        'pending_contingency', 'pending_review_manual' => 'En revisión de Mercado Pago',
                        'cc_rejected_insufficient_amount' => 'Fondos insuficientes',
                        'cc_rejected_bad_filled_security_code' => 'Código de seguridad incorrecto',
                        'cc_rejected_bad_filled_date' => 'Vencimiento incorrecto',
                        'cc_rejected_bad_filled_other' => 'Datos de la tarjeta incorrectos',
                        'cc_rejected_call_for_authorize' => 'El banco pide autorizar el pago',
                        'cc_rejected_card_disabled' => 'Tarjeta deshabilitada',
                        'cc_rejected_high_risk' => 'Rechazado por prevención de fraude',
                        'cc_rejected_max_attempts' => 'Demasiados intentos',
                        'cc_rejected_other_reason' => 'Rechazado por el banco',
                        'recycling' => 'Mercado Pago está reintentando',
                        'processed' => 'Procesado',
                        default => $state ?? '—',
                    }),
                TextColumn::make('period_start')->label('Cubre desde')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('period_end')->label('Cubre hasta')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('mp_payment_id')->label('Pago MP')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
