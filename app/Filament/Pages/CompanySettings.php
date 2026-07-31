<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class CompanySettings extends Page implements Forms\Contracts\HasForms
{

    use Forms\Concerns\InteractsWithForms;


    protected string $view = 'filament.pages.company-settings';


    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';


    protected static ?string $navigationLabel = 'Mi empresa';


    public ?array $data = [];


 public function mount(): void
{
    $user = Auth::user();

    if ($user->isSuperAdmin()) {
        abort(403);
    }

    $company = $user->company;

    if (!$company) {
        abort(403);
    }

    $this->form->fill([
        'name' => $company->name,
        'business_name' => $company->business_name,
        'cuit' => $company->cuit,
        'email' => $company->email,
        'phone' => $company->phone,
        'address' => $company->address,
        'logo' => $company->logo,
        'primary_color' => $company->primary_color,
    ]);
}

    public function getFormStatePath(): string
{
    return 'data';
}
  public function form(Schema $schema): Schema
{
    return $schema
        ->statePath('data')
        ->components([

            Section::make('Empresa')
                ->description('Información principal de la empresa.')
                ->icon('heroicon-o-building-office-2')
                ->columns(12)
                ->schema([

                    FileUpload::make('logo')
                        ->label('Logo')
                        ->image()
                        ->imageEditor()
                        ->avatar()
                        ->disk('public')
                        ->directory('companies/logos')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->acceptedFileTypes([
                            'image/png',
                            'image/jpeg',
                            'image/webp',
                        ])
                        ->columnSpan(3),

                    TextInput::make('name')
                        ->label('Nombre interno')
                        ->placeholder('Acqua Ascensores')
                        ->prefixIcon('heroicon-o-tag')
                        ->required()
                        ->columnSpan(4),

                    TextInput::make('business_name')
                        ->label('Razón social')
                        ->placeholder('Acqua Ascensores S.R.L.')
                        ->prefixIcon('heroicon-o-building-office')
                        ->columnSpan(5),

                    TextInput::make('cuit')
                        ->label('CUIT')
                        ->placeholder('30-12345678-9')
                        ->prefixIcon('heroicon-o-identification')
                        ->columnSpan(4),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->placeholder('+54 11 1234-5678')
                        ->prefixIcon('heroicon-o-phone')
                        ->columnSpan(4),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->placeholder('contacto@empresa.com')
                        ->prefixIcon('heroicon-o-envelope')
                        ->columnSpan(4),

                    TextInput::make('address')
                        ->label('Dirección')
                        ->placeholder('Av. Corrientes 1234')
                        ->prefixIcon('heroicon-o-map-pin')
                        ->columnSpanFull(),
                ]),

            Section::make('Apariencia')
                ->description('Personalizá la identidad visual del sistema.')
                ->icon('heroicon-o-paint-brush')
                ->columns(2)
                ->schema([

                    ColorPicker::make('primary_color')
                        ->label('Color principal')
                        ->default('#2563eb'),

                ]),
        ]);
}

public function save(): void
{
    $user = Auth::user();

    if ($user->isSuperAdmin()) {
        abort(403);
    }

    if (!$user->company) {
        abort(403);
    }

    $data = $this->form->getState();

    $user->company->update($data);

    \Filament\Notifications\Notification::make()
        ->title('Empresa actualizada')
        ->success()
        ->send();
}

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check()
            && ! auth()->user()->isSuperAdmin();
    }

    public static function canAccess(): bool
    {
        return auth()->check()
            && ! auth()->user()->isSuperAdmin();
    }


}
