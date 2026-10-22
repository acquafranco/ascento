<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\SupplierProduct;
use App\Services\Marketplace\MarketplaceRequests;
use App\Services\Marketplace\RequestCart;
use App\Support\Marketplace\BuyerAccess;
use App\Support\Marketplace\Catalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Pedidos de repuestos de la empresa: el pedido en armado (uno por
 * proveedor), el envío como pedido de cotización, el seguimiento (aceptar,
 * rechazar, cancelar, confirmar la recepción), el historial y «repetir».
 * Las reglas de cada paso están en MarketplaceRequests (servidor).
 */
class PartsRequests extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Pedidos de repuestos';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'pedidos-de-repuestos';

    protected static ?string $title = 'Pedidos de repuestos';

    protected string $view = 'filament.pages.marketplace.requests';

    #[Url]
    public ?int $pedido = null;

    #[Url]
    public string $estado = 'abiertos';

    /** Datos de contacto que el comprador decide compartir con el proveedor. */
    public array $contact = ['contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'delivery_area' => '', 'notes' => ''];

    /** Clave de envío por proveedor: dos clics (o un reenvío) = un solo pedido. */
    public array $keys = [];

    public string $note = '';

    public string $comment = '';

    public string $incident = '';

    public static function canAccess(): bool
    {
        return BuyerAccess::canBrowse(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! BuyerAccess::canBrowse($user)) {
            return null;
        }
        $count = (new RequestCart((int) $user->company_id))->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Productos en el pedido en armado';
    }

    public function getSubheading(): ?string
    {
        return 'Pedí cotizaciones a los proveedores y seguí cada pedido. Ascento no cobra: el pago y la entrega se coordinan con cada proveedor.';
    }

    public function mount(): void
    {
        $user = auth()->user();
        $company = $user->company;
        $this->contact = [
            'contact_name' => (string) $user->name,
            'contact_email' => (string) $user->email,
            'contact_phone' => (string) ($company?->phone ?? ''),
            'delivery_area' => trim(collect([$company?->city, $company?->province])->filter()->implode(', ')),
            'notes' => '',
        ];
    }

    private function cart(): RequestCart
    {
        return new RequestCart((int) auth()->user()->company_id);
    }

    public function keyFor(int $supplierId): string
    {
        return $this->keys[$supplierId] ??= (string) Str::uuid();
    }

    public function setQuantity(int $productId, $quantity): void
    {
        abort_unless(BuyerAccess::canOrder(auth()->user()), 403);
        $this->cart()->set($productId, max(0, min(Catalog::MAX_QUANTITY, (int) $quantity)));
    }

    public function removeItem(int $productId): void
    {
        abort_unless(BuyerAccess::canOrder(auth()->user()), 403);
        $this->cart()->remove([$productId]);
    }

    public function send(int $supplierId): void
    {
        $user = auth()->user();
        abort_unless(BuyerAccess::canOrder($user), 403);
        $this->resetErrorBag();

        $this->validate([
            'contact.contact_name' => ['required', 'string', 'max:120'],
            'contact.contact_email' => ['required', 'email', 'max:255'],
            'contact.contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]{6,40}$/'],
            'contact.delivery_area' => ['nullable', 'string', 'max:160'],
            'contact.notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'contact.contact_name.required' => 'Poné el nombre de quien recibe la cotización.',
            'contact.contact_email.required' => 'Poné un correo para que el proveedor te responda.',
            'contact.contact_email.email' => 'Revisá el correo.',
            'contact.contact_phone.regex' => 'Revisá el teléfono.',
        ]);

        $quantities = collect($this->cart()->groups()->get($supplierId, collect()))->mapWithKeys(fn ($row) => [$row['product']->id => $row['quantity']])->all();
        if ($quantities === []) {
            Notification::make()->title('No hay productos de este proveedor en tu pedido')->warning()->send();

            return;
        }

        $request = app(MarketplaceRequests::class)->create($user, $supplierId, $quantities, $this->contact, $this->keyFor($supplierId));

        $this->cart()->remove(array_keys($quantities));
        unset($this->keys[$supplierId]);
        $this->contact['notes'] = '';

        Notification::make()->title('Pedido '.$request->reference.' enviado')->body('Te avisamos cuando '.$request->supplier->name.' lo cotice.')->success()->send();
        $this->pedido = $request->id;
    }

    private function current(): MarketplaceRequest
    {
        return MarketplaceRequest::forCompany((int) auth()->user()->company_id)->with(['items', 'events', 'supplier'])->findOrFail((int) $this->pedido);
    }

    /** @param  ?int  $seenQuote  la versión de la cotización que se mostró (para aceptar) */
    public function move(string $to, ?int $seenQuote = null): void
    {
        $this->resetErrorBag();
        $request = $this->current();
        app(MarketplaceRequests::class)->buyerMove(auth()->user(), $request, $to, $this->note, $seenQuote);
        $this->note = '';

        Notification::make()->title(match ($to) {
            MarketplaceRequest::ACCEPTED => 'Cotización aceptada. Coordiná el pago y la entrega con el proveedor.',
            MarketplaceRequest::COMPLETED => 'Pedido completado. ¡Gracias por confirmarlo!',
            MarketplaceRequest::REJECTED => 'Cotización rechazada.',
            default => 'Pedido cancelado.',
        })->success()->send();
    }

    /** Comentario para el proveedor (queda en el historial y le avisa). */
    public function sendComment(): void
    {
        $this->resetErrorBag();
        app(MarketplaceRequests::class)->comment(auth()->user(), $this->current(), $this->comment);
        $this->comment = '';
        Notification::make()->title('Comentario enviado')->body('Le avisamos al proveedor.')->success()->send();
    }

    /** Incidencia para el equipo de Ascento. */
    public function sendIncident(): void
    {
        $this->resetErrorBag();
        app(MarketplaceRequests::class)->reportIncident(auth()->user(), $this->current(), $this->incident);
        $this->incident = '';
        Notification::make()->title('Le avisamos al equipo de Ascento')->body('Te respondemos en este pedido.')->success()->send();
    }

    /** Repetir: vuelve a sumar al pedido en armado lo que siga disponible. */
    public function repeat(): void
    {
        abort_unless(BuyerAccess::canOrder(auth()->user()), 403);
        $request = $this->current();
        $visible = SupplierProduct::visible()->whereIn('id', $request->items->pluck('supplier_product_id')->filter())->pluck('id')->all();
        $cart = $this->cart();
        foreach ($request->items as $item) {
            if (in_array($item->supplier_product_id, $visible, true)) {
                $cart->add($item->supplier_product_id, $item->quantity);
            }
        }
        $missing = $request->items->count() - count($visible);

        $notice = Notification::make();
        if ($visible === []) {
            $notice->title('Ninguno de esos productos sigue disponible')->warning();
        } else {
            $notice->title('Agregamos '.count($visible).(count($visible) === 1 ? ' producto' : ' productos').' a tu pedido')->body($missing > 0 ? $missing.($missing === 1 ? ' ya no está disponible.' : ' ya no están disponibles.') : null)->success();
        }
        $notice->send();
        $this->pedido = null;
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $base = ['canOrder' => BuyerAccess::canOrder($user)];

        if ($this->pedido !== null) {
            $request = $this->current();

            return $base + ['mode' => 'detail', 'row' => $request, 'operations' => $request->supplier->completedOperations(), 'supplierAvailable' => $request->supplier->isOperating()];
        }

        $history = in_array($this->estado, ['abiertos', 'historial'], true) ? $this->estado : 'abiertos';

        $groups = $this->cart()->groups();
        foreach ($groups->keys() as $supplierId) {
            $this->keyFor((int) $supplierId);
        }

        return $base + [
            'mode' => 'list',
            'groups' => $groups,
            'rows' => MarketplaceRequest::forCompany((int) $user->company_id)
                ->when($history === 'abiertos', fn ($q) => $q->whereIn('status', MarketplaceRequest::OPEN), fn ($q) => $q->whereNotIn('status', MarketplaceRequest::OPEN))
                ->with('supplier:id,name')->withCount('items')->latest()->limit(100)->get(),
            'tab' => $history,
        ];
    }
}
