<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProductPhoto;
use App\Models\User;
use App\Services\Activity\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cierre de un proveedor con eliminación de sus datos (pedido del proveedor
 * o baja decidida por Ascento). Solo el SuperAdmin, con confirmación.
 *
 * Se borra: sus usuarios (y sus avisos, dispositivos y sesiones), productos,
 * fotos (también los archivos), favoritos y reportes de esos productos, y los
 * datos de contacto y descripción del perfil.
 *
 * Se conserva, sin datos personales del proveedor:
 * - la fila del proveedor como «cuenta cerrada» con su nombre comercial,
 *   país e identificación fiscal: los pedidos de las empresas compradoras y
 *   los cobros de Ascento la referencian (historial de terceros y registro
 *   contable);
 * - los pedidos y cotizaciones (copias de los productos incluidas): son
 *   también historial de cada empresa compradora;
 * - los cobros y destacados (supplier_payments, featured_placements);
 * - las solicitudes de contacto a su perfil profesional, si quedaron de la
 *   función retirada «Profesionales y servicios» (cerradas; el perfil
 *   queda archivado y sin descripción ni datos).
 *
 * No se puede cerrar con una suscripción que la pasarela pueda seguir
 * cobrando: primero se cancela.
 */
class SupplierEraser
{
    public function erase(Supplier $supplier, User $actor, string $confirmation, string $reason): string
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if (mb_strtolower(trim($confirmation)) !== mb_strtolower(trim($supplier->name))) {
            throw ValidationException::withMessages(['confirm' => 'El nombre no coincide: no se borró nada.']);
        }
        if ($supplier->closed_at !== null) {
            throw ValidationException::withMessages(['confirm' => 'Este proveedor ya está cerrado.']);
        }
        if ($supplier->subscription?->isBillable()) {
            throw ValidationException::withMessages(['confirm' => 'Tiene una suscripción que puede seguir cobrando: cancelala primero.']);
        }

        $files = SupplierProductPhoto::where('supplier_id', $supplier->id)->get(['path', 'thumb_path'])
            ->flatMap(fn ($p) => [$p->path, $p->thumb_path])->filter()->all();
        $openRequests = MarketplaceRequest::forSupplier($supplier->id)->whereIn('status', MarketplaceRequest::OPEN)->get();

        $counts = DB::transaction(function () use ($supplier) {
            $users = User::withTrashed()->where('supplier_id', $supplier->id)->pluck('id')->all();
            $products = $supplier->products()->pluck('id')->all();

            FeaturedPlacement::where('supplier_id', $supplier->id)->whereIn('status', [FeaturedPlacement::ACTIVE, FeaturedPlacement::PENDING_PAYMENT])
                ->update(['status' => FeaturedPlacement::CANCELLED, 'status_reason' => 'Cuenta del proveedor cerrada.', 'checkout_url' => null]);
            // Perfil profesional (función retirada; si quedó alguno): archivado y sin datos; las solicitudes de las empresas se conservan cerradas.
            if ($profileId = DB::table('service_profiles')->where('supplier_id', $supplier->id)->value('id')) {
                $received = DB::table('service_contact_requests')->where('service_profile_id', $profileId)->pluck('id')->all();
                DB::table('service_contact_messages')->whereIn('service_contact_request_id', $received ?: [0])->where('side', 'professional')->update(['user_id' => null, 'author_name' => null, 'updated_at' => now()]);
                DB::table('service_contact_requests')->whereIn('id', $received ?: [0])->where('status', '!=', 'closed')->update(['status' => 'closed', 'closed_by' => 'ascento', 'closed_at' => now(), 'updated_at' => now()]);
                DB::table('service_profile_terms')->where('service_profile_id', $profileId)->delete();
                DB::table('service_profiles')->where('id', $profileId)->update([
                    'status' => 'archived', 'headline' => null, 'description' => '—', 'experience' => null, 'brands' => null, 'services' => null,
                    'provinces' => null, 'localities' => null, 'website' => null, 'search_text' => null, 'updated_at' => now(),
                ]);
            }
            DB::table('marketplace_favorites')->whereIn('supplier_product_id', $products ?: [0])->delete();
            DB::table('marketplace_reports')->whereIn('supplier_product_id', $products ?: [0])->delete();
            DB::table('supplier_product_photos')->where('supplier_id', $supplier->id)->delete();
            // Los ítems de los pedidos guardan copia del producto; su referencia queda en null.
            $deletedProducts = DB::table('supplier_products')->where('supplier_id', $supplier->id)->delete();

            if ($users !== []) {
                DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
                DB::table('push_subscriptions')->where('subscribable_type', User::class)->whereIn('subscribable_id', $users)->delete();
                DB::table('sessions')->whereIn('user_id', $users)->delete();
                $emails = DB::table('users')->whereIn('id', $users)->pluck('email')->all();
                DB::table('password_reset_tokens')->whereIn('email', $emails ?: [''])->delete();
                DB::table('users')->whereIn('id', $users)->delete();
            }

            $supplier->forceFill([
                'status' => Supplier::SUSPENDED,
                'status_reason' => 'Cuenta cerrada.',
                'closed_at' => now(),
                'legal_name' => null,
                'description' => null,
                'contact_email' => null,
                'contact_phone' => null,
                'website' => null,
                'coverage' => null,
                'brands' => null,
                'admin_notes' => trim(($supplier->admin_notes ? $supplier->admin_notes."\n" : '').'Cerrado el '.now()->format('d/m/Y').'.'),
            ])->save();

            return ['users' => count($users), 'products' => $deletedProducts];
        });

        $files === [] || Storage::disk('local')->delete($files);
        PlatformAudit::record('marketplace.supplier.erased', $supplier, ['reason' => $reason, 'users' => $counts['users'], 'products' => $counts['products']]);

        // Las empresas con pedidos abiertos se enteran (el pedido se conserva).
        $notify = app(MarketplaceNotifier::class);
        $openRequests->each(fn (MarketplaceRequest $r) => $notify->buyer($r, 'Proveedor dado de baja', $supplier->name.' cerró su cuenta en Ascento. Tu pedido '.$r->reference.' se conserva en tu historial; podés cancelarlo.', 'supplier-closed', mail: true));

        return $counts['users'].' usuario(s) y '.$counts['products'].' producto(s) eliminados. Se conservan los pedidos de los compradores y los cobros.';
    }
}
