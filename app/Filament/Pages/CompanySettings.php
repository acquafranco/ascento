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
    return [
        Action::make('testWhatsApp')
            ->label('Enviar mensaje de prueba')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->action(function () {
                $company = auth()->user()->company;

                $recipient = preg_replace('/\D/', '', (string) auth()->user()->phone);

                if (! str_starts_with($recipient, '54')) {
                    $recipient = '54' . $recipient;
                }

                app(WhatsAppService::class)->send(
                    $company,
                    $recipient,
                    'Mensaje enviado desde Laravel'
                );
                \Filament\Notifications\Notification::make()
                    ->title('Se intentó enviar el mensaje de prueba.')
                    ->success()
                    ->send();
            }),
        Action::make('connectWhatsApp')
            ->label('Conectar WhatsApp Business')
            ->icon('heroicon-o-link')
            ->color('success')
            ->url(fn () => route('whatsapp.connect', [
                'company' => auth()->user()->company,
            ])),
    ];
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
