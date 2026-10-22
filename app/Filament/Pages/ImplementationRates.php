<?php

namespace App\Filament\Pages;

use App\Models\ImplementationRate;
use App\Services\Activity\PlatformAudit;
use App\Services\Sales\CalculatorInput;
use App\Services\Sales\ImplementationQuote;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Tarifas del servicio de implementación inicial (calculadora de la Welcome),
 * solo para el SuperAdmin. Mientras no estén publicadas, la calculadora no
 * muestra importes («a confirmar»). Cada cambio queda en el registro de
 * actividad.
 */
class ImplementationRates extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Tarifas de implementación';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'tarifas-de-implementacion';

    protected static ?string $title = 'Tarifas de implementación';

    protected string $view = 'filament.pages.implementation-rates';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Lo que ve un visitante en la calculadora cuando elige «Quiero que Ascento me ayude». Sin publicar, se muestra «a confirmar».';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(ImplementationRate::current()->only([
            'base_fee', 'included_clients', 'included_buildings', 'included_elevators', 'per_client', 'per_building', 'per_elevator',
            'factor_tidy', 'factor_mixed', 'factor_paper', 'training_fee', 'launch_support_fee', 'max_quote', 'published',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label, ?string $help = null) => TextInput::make($name)->label($label)->numeric()->minValue(0)->maxValue(999999999)->prefix('$')->helperText($help);
        $count = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->integer()->minValue(0)->maxValue(100000)->required();
        $factor = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(1)->maxValue(5)->step(0.05)->required();

        return $schema->statePath('data')->components([
            Section::make('Implementación')->columns(['default' => 1, 'md' => 3])->schema([
                $money('base_fee', 'Tarifa base', 'Cubre lo incluido de abajo.')->columnSpan(['md' => 3]),
                $count('included_clients', 'Clientes incluidos'),
                $count('included_buildings', 'Edificios incluidos'),
                $count('included_elevators', 'Ascensores incluidos'),
                $money('per_client', 'Por cliente adicional'),
                $money('per_building', 'Por edificio adicional'),
                $money('per_elevator', 'Por ascensor adicional'),
            ]),
            Section::make('Estado de los datos')->description('Multiplica solo la parte variable (las unidades adicionales). 1 = sin recargo.')->columns(['default' => 1, 'md' => 3])->schema([
                $factor('factor_tidy', CalculatorInput::DATA_STATES['tidy']),
                $factor('factor_mixed', CalculatorInput::DATA_STATES['mixed']),
                $factor('factor_paper', CalculatorInput::DATA_STATES['paper']),
            ]),
            Section::make('Servicios opcionales y tope')->columns(['default' => 1, 'md' => 3])->schema([
                $money('training_fee', CalculatorInput::OPTIONS['training'], 'Vacío: «a confirmar».'),
                $money('launch_support_fee', CalculatorInput::OPTIONS['launch_support'], 'Vacío: «a confirmar».'),
                $money('max_quote', 'Tope para cotizar automático', 'Por encima, se ofrece una propuesta personalizada. Vacío: sin tope.'),
            ]),
            Section::make('Publicación')->schema([
                Forms\Components\Toggle::make('published')->label('Mostrar estos importes en la calculadora')
                    ->helperText('Necesita la tarifa base y los tres importes por unidad. Apagado: la calculadora dice «a confirmar».'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        $rate = ImplementationRate::query()->orderBy('id')->first() ?? new ImplementationRate;
        $rate->fill($data + ['currency' => 'ARS']);
        $rate->forceFill(['updated_by' => Auth::id()]);

        if ($rate->published && ! $rate->isReady()) {
            Notification::make()->title('Falta completar tarifas')->body('Para publicar, cargá la tarifa base y los importes por cliente, edificio y ascensor.')->warning()->send();

            return;
        }

        $rate->save();
        PlatformAudit::record('implementation_rates.updated', $rate, $rate->getChanges(), 'Tarifas de implementación');

        // Ejemplo con los valores guardados, para revisar la fórmula.
        $example = app(ImplementationQuote::class)->quote(new CalculatorInput(30, 50, 15, 3, 'mixed', ['training']), $rate);
        Notification::make()->title('Tarifas guardadas')
            ->body('Ejemplo (30 edificios, 50 ascensores, 15 clientes, datos en varias planillas, con capacitación): '.ImplementationQuote::format($example['total'] ?? $example['implementation']).($example['status'] !== 'priced' ? ' ('.$example['status'].')' : ''))
            ->success()->send();
    }
}
