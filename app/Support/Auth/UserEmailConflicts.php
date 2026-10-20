<?php

namespace App\Support\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas que comparten el mismo correo normalizado (datos anteriores a la
 * regla «un correo = una cuenta»). Solo consulta: no borra, no fusiona, no
 * cambia contraseñas ni empresas. Resolverlas es una decisión manual.
 */
final class UserEmailConflicts
{
    public const UNIQUE_INDEX = 'users_email_key_unique';

    public const PLAIN_INDEX = 'users_email_key_index';

    /**
     * @return list<array{key: string, users: list<array{id: int, role: ?string, company_id: ?int, client_id: ?int, deactivated: bool, super_admin: bool}>}>
     */
    public static function find(): array
    {
        $keys = DB::table('users')->selectRaw('lower(trim(email)) as k')->groupByRaw('lower(trim(email))')->havingRaw('count(*) > 1')->pluck('k');

        return $keys->map(fn ($key) => [
            'key' => (string) $key,
            'users' => DB::table('users')->whereRaw('lower(trim(email)) = ?', [$key])->orderBy('id')
                ->get(['id', 'role', 'company_id', 'client_id', 'deleted_at', 'is_super_admin'])
                ->map(fn ($u) => [
                    'id' => (int) $u->id, 'role' => $u->role, 'company_id' => $u->company_id ? (int) $u->company_id : null,
                    'client_id' => $u->client_id ? (int) $u->client_id : null, 'deactivated' => $u->deleted_at !== null, 'super_admin' => (bool) $u->is_super_admin,
                ])->all(),
        ])->values()->all();
    }

    public static function enforced(): bool
    {
        return Schema::hasIndex('users', self::UNIQUE_INDEX);
    }

    /** Crea el índice único (solo si no hay repetidos). */
    public static function enforce(): bool
    {
        if (self::enforced()) {
            return true;
        }
        if (self::find() !== []) {
            return false;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', self::PLAIN_INDEX)) {
                $table->dropIndex(self::PLAIN_INDEX);
            }
            $table->unique('email_key', self::UNIQUE_INDEX);
        });

        return true;
    }

    /** «an***@ejemplo.com»: para listar sin exponer el correo completo. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
