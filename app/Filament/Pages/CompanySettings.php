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
        $company = Auth::user()->company;

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

                Section::make('Datos de empresa')
                    ->schema([

                       FileUpload::make('logo')
                            ->label('Logo de empresa')
                            ->image()
                            ->disk('public')
                            ->directory('companies/logos')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->acceptedFileTypes([
                                'image/png',
                                'image/jpeg',
                                'image/webp',
                            ])
                            ->dehydrated(true),


                        TextInput::make('name')
                            ->label('Nombre interno')
                            ->required(),


                        TextInput::make('business_name')
                            ->label('Nombre comercial / Razón social'),


                        TextInput::make('cuit')
                            ->label('CUIT'),


                        TextInput::make('email')
                            ->label('Email empresarial')
                            ->email(),


                        TextInput::make('phone')
                            ->label('Teléfono'),


                        TextInput::make('address')
                            ->label('Dirección'),


                        ColorPicker::make('primary_color')
                            ->label('Color principal'),

                    ])
            ]);
    }

            public function save(): void
            {
                $data = $this->form->getState();

                if (isset($data['logo']) && is_array($data['logo'])) {

                    $data['logo'] = $data['logo'][0] ?? null;

                }


                Auth::user()
                    ->company
                    ->update($data);


                \Filament\Notifications\Notification::make()
                    ->title('Empresa actualizada')
                    ->success()
                    ->send();
            }

}
