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
use Filament\Actions\Action;
use App\Services\WhatsAppService;

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
        'whatsapp_business_id' => $company->whatsapp_business_id,
        'whatsapp_waba_id' => $company->whatsapp_waba_id,
        'whatsapp_phone_number_id' => $company->whatsapp_phone_number_id,
        'whatsapp_access_token' => $company->whatsapp_access_token,
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

            Section::make('WhatsApp Business')
                ->description('Configuración temporal para WhatsApp Business. Esta configuración es provisoria hasta implementar la conexión automática con Meta.')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('whatsapp_status')
                        ->label('Estado de conexión')
                        ->content(function () {
                            return auth()->user()->company?->whatsapp_connected
                                ? 'WhatsApp Business conectado ✅'
                                : 'WhatsApp Business no conectado';
                        }),
                    Forms\Components\Placeholder::make('connect_whatsapp')
                        ->hiddenLabel()
                        ->content(new \Illuminate\Support\HtmlString(
                            '<a href="' . route('whatsapp.connect', ['company' => auth()->user()->company]) . '" style="display:inline-flex;align-items:center;gap:8px;background:#22c55e;color:#fff;padding:10px 16px;border-radius:10px;font-weight:600;text-decoration:none;box-shadow:0 2px 8px rgba(34,197,94,.25);">'
                            . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20.52 3.48A11.86 11.86 0 0 0 12.06 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.6 5.96L0 24l6.33-1.66a11.88 11.88 0 0 0 5.73 1.46h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.24-6.16-3.45-8.42ZM12.07 21.8a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.76.99 1-3.66-.23-.38a9.87 9.87 0 1 1 8.39 4.64Zm5.43-7.42c-.3-.15-1.78-.88-2.06-.98-.27-.1-.47-.15-.67.15-.2.3-.77.98-.95 1.18-.17.2-.35.23-.65.08-.3-.15-1.27-.47-2.42-1.5-.9-.8-1.5-1.8-1.68-2.1-.18-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.38-.02-.53-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.53.08-.8.38-.28.3-1.06 1.03-1.06 2.52 0 1.48 1.08 2.92 1.23 3.12.15.2 2.12 3.23 5.13 4.53.72.31 1.28.5 1.72.64.72.23 1.37.2 1.89.12.58-.09 1.78-.73 2.03-1.43.25-.7.25-1.3.18-1.43-.08-.12-.28-.2-.58-.35Z"/></svg>'
                            . 'Conectar WhatsApp Business</a>'
                        ))
                        ->columnSpanFull(),
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

    $data['whatsapp_connected'] = ! empty($data['whatsapp_access_token'])
        && ! empty($data['whatsapp_phone_number_id']);

    $user->company->update($data);

    \Filament\Notifications\Notification::make()
        ->title('Empresa actualizada')
        ->success()
        ->send();
}

protected function getHeaderActions(): array
{
    return [];
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
