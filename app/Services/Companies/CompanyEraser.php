<?php

namespace App\Services\Companies;

use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Eliminación DEFINITIVA de una empresa (solo SuperAdmin). Desactivarla
 * (soft delete) conserva todo; esto borra la empresa y únicamente lo suyo:
 *
 * - Sus datos, en orden (hijos antes que padres, sin depender de cascadas):
 *   fotos, reportes, stock, cobranzas, remitos, visitas, órdenes,
 *   presupuestos, servicios, equipos, edificios, clientes, accesos al
 *   portal, avisos, exportaciones, suscripciones y sus usuarios.
 * - Sus archivos privados y públicos (fotos, videos, documentos de
 *   equipos, exportaciones y logo), recién después de confirmar la base:
 *   si algo falla, no se borra ningún archivo.
 * - Usuarios: los de la empresa se borran (con sus sesiones, avisos y
 *   suscripciones push). Un usuario del portal que también tiene acceso a
 *   otra empresa se conserva y queda en esa otra empresa (solo pierde el
 *   acceso a esta). El SuperAdmin nunca se borra.
 * - Nunca toca otras empresas ni datos globales (planes, webhooks,
 *   respaldos del sistema). Al final verifica que no quedó ninguna fila con
 *   el id de la empresa en ninguna tabla: si quedara, se revierte todo.
 *
 * Bloqueos (no se borra nada): una suscripción de Mercado Pago que todavía
 * puede cobrar (hay que cancelarla primero) o datos de otra empresa que
 * dependan de un usuario de esta (no debería pasar: se informa).
 *
 * Se registra en el log de la aplicación (no hay tabla de auditoría):
 * quién, cuándo, qué empresa y cuántos registros y archivos.
 */
class CompanyEraser
{
    /** Estados de Mercado Pago que pueden seguir cobrando. */
    private const BILLABLE = [Subscription::PENDING, Subscription::AUTHORIZED, Subscription::PAST_DUE, Subscription::PAUSED];

    /** Carpetas por empresa ({raíz}/{id}/…) en cada disco. */
    private const COMPANY_DIRECTORIES = [['local', 'visits'], ['local', 'reports'], ['local', 'elevators'], ['local', 'exports'], ['public', 'reports']];

    /**
     * Qué se va a borrar (para la confirmación) y qué lo impide.
     *
     * @return array{counts: array<string, int>, blockers: array<int, string>, shared_users: int}
     */
    public function preview(Company $company): array
    {
        $id = (int) $company->id;
        $users = $this->companyUsers($id);
        $shared = $this->sharedPortalUsers($id, $users);

        return [
            'counts' => [
                'Usuarios' => count($users) - count($shared),
                'Clientes' => DB::table('clients')->where('company_id', $id)->count(),
                'Edificios' => DB::table('buildings')->where('company_id', $id)->count(),
                'Remitos' => DB::table('delivery_notes')->where('company_id', $id)->count(),
                'Visitas' => DB::table('building_visits')->where('company_id', $id)->count(),
                'Órdenes de trabajo' => DB::table('work_orders')->where('company_id', $id)->count(),
                'Reportes' => DB::table('reports')->where('company_id', $id)->count(),
                'Presupuestos' => DB::table('quotes')->where('company_id', $id)->count(),
                'Fotos' => DB::table('visit_photos')->where('company_id', $id)->count() + DB::table('report_photos')->where('company_id', $id)->count(),
            ],
            'blockers' => $this->blockers($id, $users, $shared),
            'shared_users' => count($shared),
        ];
    }

    /**
     * @return array{rows: int, files: int}
     *
     * @throws ValidationException si la confirmación no coincide o algo lo impide
     */
    public function erase(Company $company, User $actor, string $confirmation): array
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if (trim($confirmation) !== $company->name) {
            throw ValidationException::withMessages(['confirmation' => 'Escribí el nombre exacto de la empresa para confirmar.']);
        }

        $id = (int) $company->id;
        $name = $company->name;
        $rows = 0;
        $files = [];

        DB::transaction(function () use ($id, &$rows, &$files) {
            // Bloquea la empresa: nadie la usa mientras se borra.
            abort_unless(DB::table('companies')->where('id', $id)->lockForUpdate()->exists(), 404);

            // Lectura con bloqueo: una exportación que se está tomando en este
            // momento termina de tomarse antes (y entonces bloquea el borrado)
            // o espera y ya no encuentra su fila.
            DB::table('company_exports')->where('company_id', $id)->lockForUpdate()->get(['id']);

            $users = $this->companyUsers($id);
            $shared = $this->sharedPortalUsers($id, $users);

            if ($blockers = $this->blockers($id, $users, $shared)) {
                throw ValidationException::withMessages(['confirmation' => implode(' ', $blockers)]);
            }

            $files = $this->files($id);
            $rows = $this->deleteRows($id, $users, $shared);
            $this->ensureNothingLeft($id);
        });

        // La base ya no tiene nada de la empresa: recién ahora, sus archivos.
        $deleted = $this->deleteFiles($id, $files);

        if ((int) session('selected_company_id') === $id) {
            session()->forget('selected_company_id');
        }

        Log::warning('Empresa eliminada definitivamente', [
            'company_id' => $id,
            'company_name' => $name,
            'by_user_id' => $actor->id,
            'rows' => $rows,
            'files' => $deleted,
        ]);

        return ['rows' => $rows, 'files' => $deleted];
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIOS
    |--------------------------------------------------------------------------
    */

    /** @return array<int, int> usuarios de la empresa (nunca un SuperAdmin) */
    private function companyUsers(int $id): array
    {
        return DB::table('users')->where('company_id', $id)->where('is_super_admin', false)->pluck('id')->map(fn ($u) => (int) $u)->all();
    }

    /**
     * Usuarios del portal de esta empresa que también tienen (o tuvieron) un
     * acceso al portal de OTRA empresa, aunque esté desactivado: se conservan
     * y quedan en esa otra empresa (primero la de un acceso activo). Así no
     * se borra ni un acceso desactivado de otra empresa.
     *
     * @return array<int, array{company_id: int, client_id: int}>
     */
    private function sharedPortalUsers(int $id, array $users): array
    {
        if ($users === []) {
            return [];
        }

        return DB::table('portal_memberships')
            ->join('users', 'users.id', '=', 'portal_memberships.user_id')
            ->whereIn('portal_memberships.user_id', $users)
            ->where('users.role', User::ROLE_CLIENT)
            ->where('portal_memberships.company_id', '!=', $id)
            ->orderByRaw('case when portal_memberships.deactivated_at is null then 0 else 1 end')
            ->orderBy('portal_memberships.id')
            ->get(['portal_memberships.user_id', 'portal_memberships.company_id', 'portal_memberships.client_id'])
            ->unique('user_id')
            ->mapWithKeys(fn ($m) => [(int) $m->user_id => ['company_id' => (int) $m->company_id, 'client_id' => (int) $m->client_id]])
            ->all();
    }

    /**
     * Filas de OTRA empresa que dependen de un usuario que se borraría: no
     * se borra nada (ni por cascada) y se informa. Un usuario que no es del
     * portal nunca se "muda" a otra empresa (ganaría acceso a ella).
     */
    private function foreignRowsOf(int $id, array $erased): int
    {
        if ($erased === []) {
            return 0;
        }

        $direct = collect([['building_visits', 'user_id'], ['delivery_notes', 'user_id'], ['reports', 'user_id'], ['quotes', 'created_by'], ['portal_memberships', 'user_id']])
            ->sum(fn ($ref) => DB::table($ref[0])->whereIn($ref[1], $erased)->where('company_id', '!=', $id)->count());

        $viaParent = collect([
            ['building_user', 'building_id', 'buildings'],
            ['client_portal_buildings', 'building_id', 'buildings'],
            ['work_order_user', 'work_order_id', 'work_orders'],
            ['work_order_participants', 'work_order_id', 'work_orders'],
            ['building_visit_participants', 'building_visit_id', 'building_visits'],
        ])->sum(fn ($ref) => DB::table($ref[0])->join($ref[2], $ref[2].'.id', '=', $ref[0].'.'.$ref[1])
            ->whereIn($ref[0].'.user_id', $erased)->where($ref[2].'.company_id', '!=', $id)->count());

        return $direct + $viaParent;
    }

    /** @return array<int, string> */
    private function blockers(int $id, array $users, array $shared): array
    {
        $blockers = [];

        $billable = DB::table('subscriptions')->where('company_id', $id)->where('provider', 'mercadopago')
            ->whereNotNull('provider_subscription_id')->whereIn('status', self::BILLABLE)->exists();

        if ($billable) {
            $blockers[] = 'Tiene una suscripción de Mercado Pago que puede seguir cobrando: cancelala primero desde «Suscripción» y después eliminá la empresa.';
        }

        // Datos de OTRA empresa que dependen de un usuario que se borraría.
        $foreign = $this->foreignRowsOf($id, array_values(array_diff($users, array_keys($shared))));

        if ($foreign > 0) {
            $blockers[] = "Hay {$foreign} registros de otra empresa vinculados a usuarios de esta: revisalo antes de eliminarla.";
        }

        // Una exportación en curso escribiría su archivo después del borrado.
        if (DB::table('company_exports')->where('company_id', $id)->where('status', CompanyExport::GENERATING)->exists()) {
            $blockers[] = 'Se está generando una exportación de sus datos: esperá a que termine (uno o dos minutos) y volvé a intentarlo.';
        }

        return $blockers;
    }

    /*
    |--------------------------------------------------------------------------
    | BORRADO EN LA BASE
    |--------------------------------------------------------------------------
    */

    private function deleteRows(int $id, array $users, array $shared): int
    {
        $n = 0;
        $of = fn (string $table) => DB::table($table)->where('company_id', $id);
        $ids = fn (string $table) => $of($table)->pluck('id')->all();

        $buildings = $ids('buildings');
        $visits = $ids('building_visits');
        $workOrders = $ids('work_orders');
        $clients = $ids('clients');
        $erased = array_values(array_diff($users, array_keys($shared)));

        // Fotos, reportes y sus archivos (filas; los archivos van después).
        foreach (['visit_photos', 'report_videos', 'report_photos', 'reports'] as $table) {
            $n += $of($table)->delete();
        }

        // Stock: primero se cortan las referencias cruzadas movimiento ↔ material.
        $of('stock_movements')->update(['work_order_material_id' => null]);
        $of('work_order_materials')->update(['stock_movement_id' => null]);
        foreach (['work_order_materials', 'stock_movements', 'stock_items', 'receivable_payments', 'receivables', 'delivery_notes'] as $table) {
            $n += $of($table)->delete();
        }

        $n += DB::table('building_visit_participants')->whereIn('building_visit_id', $visits ?: [0])->delete();
        $n += $of('building_visits')->delete();
        $n += $of('building_visit_plans')->delete();

        foreach (['work_order_participants', 'work_order_user'] as $table) {
            $n += DB::table($table)->whereIn('work_order_id', $workOrders ?: [0])->delete();
        }
        $n += $of('work_orders')->delete();

        foreach (['quote_events', 'quote_items', 'quotes', 'maintenance_services', 'elevator_documents', 'elevators'] as $table) {
            $n += $of($table)->delete();
        }

        foreach (['client_portal_buildings', 'building_user'] as $table) {
            $n += DB::table($table)->whereIn('building_id', $buildings ?: [0])->delete();
        }
        $n += $of('buildings')->delete();

        $n += $of('portal_memberships')->delete();
        $n += $of('notifications')->delete();
        $n += $of('company_export_downloads')->delete();
        $n += $of('company_exports')->delete();
        $n += $of('subscription_payments')->delete();
        $n += $of('subscriptions')->delete();
        $n += $of('photo_sections')->delete();

        // Usuarios del portal compartidos: quedan en la otra empresa (con su cliente de allá).
        foreach ($shared as $userId => $home) {
            DB::table('users')->where('id', $userId)->update(['company_id' => $home['company_id'], 'client_id' => $home['client_id'], 'updated_at' => now()]);
        }

        // Cualquier otro usuario (de otra empresa) apuntando a un cliente de esta: sin cliente.
        DB::table('users')->whereIn('client_id', $clients ?: [0])->update(['client_id' => null]);

        // El SuperAdmin que tuviera esta empresa como propia queda sin empresa (nunca se borra).
        DB::table('users')->where('company_id', $id)->where('is_super_admin', true)->update(['company_id' => null]);

        if ($erased !== []) {
            $n += DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $erased)->delete();
            $n += DB::table('push_subscriptions')->where('subscribable_type', User::class)->whereIn('subscribable_id', $erased)->delete();
            $n += DB::table('sessions')->whereIn('user_id', $erased)->delete();
            $emails = DB::table('users')->whereIn('id', $erased)->pluck('email')->all();
            DB::table('password_reset_tokens')->whereIn('email', $emails ?: [''])->delete();
            foreach (['help_dismissals', 'building_user', 'work_order_user', 'work_order_participants', 'building_visit_participants', 'client_portal_buildings', 'portal_memberships'] as $table) {
                $n += DB::table($table)->whereIn('user_id', $erased)->delete();
            }
            $n += DB::table('users')->whereIn('id', $erased)->delete();
        }

        $n += $of('clients')->delete();
        $n += DB::table('companies')->where('id', $id)->delete();

        return $n;
    }

    /** Ninguna tabla conserva filas de la empresa (también las que se agreguen en el futuro). */
    private function ensureNothingLeft(int $id): void
    {
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (Schema::hasColumn($table, 'company_id') && DB::table($table)->where('company_id', $id)->exists()) {
                throw new \RuntimeException("La eliminación de la empresa {$id} dejó filas en {$table}: no se borró nada.");
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ARCHIVOS
    |--------------------------------------------------------------------------
    */

    /** @return array<int, array{0: string, 1: string}> [disco, path] de los archivos de la empresa */
    private function files(int $id): array
    {
        $files = [];
        $add = function (string $disk, ?string $path, string $prefix) use (&$files, $id) {
            // Solo dentro de la carpeta de ESTA empresa y sin saltos de directorio.
            if (is_string($path) && $path !== '' && str_starts_with($path, $prefix.'/'.$id.'/') && ! str_contains($path, '..')) {
                $files[] = [$disk, $path];
            }
        };

        DB::table('visit_photos')->where('company_id', $id)->get(['path', 'thumb_path'])
            ->each(function ($p) use ($add) {
                $add('local', $p->path, 'visits');
                $add('local', $p->thumb_path, 'visits');
            });
        DB::table('report_photos')->where('company_id', $id)->get(['path', 'thumb_path'])
            ->each(function ($p) use ($add) {
                foreach (['local', 'public'] as $disk) {
                    $add($disk, $p->path, 'reports');
                } $add('local', $p->thumb_path, 'reports');
            });
        DB::table('report_videos')->where('company_id', $id)->pluck('path')->each(fn ($path) => $add('local', $path, 'reports'));
        DB::table('elevator_documents')->where('company_id', $id)->pluck('path')->each(fn ($path) => $add('local', $path, 'elevators'));
        DB::table('company_exports')->where('company_id', $id)->pluck('path')->each(fn ($path) => $add('local', $path, 'exports'));

        // Foto vieja de reportes (columna reports.photo) y logo: solo si ninguna otra empresa usa ese archivo.
        $legacy = DB::table('reports')->where('company_id', $id)->whereNotNull('photo')->pluck('photo')->all();
        $logo = DB::table('companies')->where('id', $id)->value('logo');
        foreach (array_filter([...$legacy, $logo]) as $path) {
            $usedElsewhere = DB::table('reports')->where('company_id', '!=', $id)->where('photo', $path)->exists()
                || DB::table('companies')->where('id', '!=', $id)->where('logo', $path)->exists();

            if (! $usedElsewhere && ! str_contains($path, '..')) {
                $files[] = ['public', $path];
            }
        }

        return $files;
    }

    /**
     * Carpetas privadas o públicas de empresas que ya no existen (por
     * ejemplo, un archivo que un proceso terminó de escribir justo después
     * de eliminar la empresa). Solo carpetas con un id numérico de empresa
     * inexistente (tampoco desactivada): nunca toca otra cosa.
     *
     * @return array<int, string> carpetas borradas
     */
    public function pruneOrphanFiles(): array
    {
        $existing = DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $deleted = [];

        foreach (self::COMPANY_DIRECTORIES as [$disk, $root]) {
            foreach (Storage::disk($disk)->directories($root) as $directory) {
                $name = basename($directory);

                if (ctype_digit($name) && (int) $name > 0 && ! $existing->has((int) $name)) {
                    Storage::disk($disk)->deleteDirectory($directory);
                    $deleted[] = $disk.':'.$directory;
                }
            }
        }

        if ($deleted !== []) {
            Log::warning('Archivos de empresas eliminadas borrados', ['directories' => $deleted]);
        }

        return $deleted;
    }

    private function deleteFiles(int $id, array $files): int
    {
        $deleted = 0;

        foreach ($files as [$disk, $path]) {
            try {
                if (Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo borrar un archivo de la empresa eliminada', ['company_id' => $id, 'disk' => $disk, 'path' => $path]);
            }
        }

        // Lo que haya quedado en las carpetas de la empresa (miniaturas, archivos sueltos).
        foreach (self::COMPANY_DIRECTORIES as [$disk, $root]) {
            $directory = $root.'/'.$id;

            if ($id > 0 && Storage::disk($disk)->exists($directory)) {
                $deleted += count(Storage::disk($disk)->allFiles($directory));
                Storage::disk($disk)->deleteDirectory($directory);
            }
        }

        return $deleted;
    }
}
