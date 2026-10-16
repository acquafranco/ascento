<?php

namespace App\Support;

use App\Models\Company;
use Closure;

/**
 * Memoria de UN pedido: evita repetir la misma consulta muchas veces mientras
 * se arma una página (empresa y plan, estado de un administrativo, guías de
 * ayuda visibles…).
 *
 * Se vacía al terminar cada pedido (RequestHandled) y ante cualquier
 * escritura en empresas, suscripciones, planes, usuarios o accesos al portal
 * (también las masivas): ver AppServiceProvider. En procesos de consola largos (scheduler, colas) no se
 * usa, para no arrastrar datos viejos entre tareas; en las pruebas sí.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function enabled(): bool
    {
        return ! app()->runningInConsole() || app()->runningUnitTests();
    }

    public static function remember(string $key, Closure $resolve): mixed
    {
        if (! self::enabled()) {
            return $resolve();
        }

        return array_key_exists($key, self::$items) ? self::$items[$key] : (self::$items[$key] = $resolve());
    }

    public static function hasItems(): bool
    {
        return self::$items !== [];
    }

    /** ¿Esta consulta escribe en algo que la memoria guarda? (empresas, suscripciones, planes, usuarios, accesos al portal) */
    public static function invalidates(string $sql): bool
    {
        return (bool) preg_match('/^\s*(update|delete|insert|replace)\b[^;]*?\b(companies|subscriptions|subscription_plans|users|portal_memberships|client_portal_buildings)\b/i', $sql);
    }

    public static function flush(): void
    {
        self::$items = [];
    }

    /** Empresa por id (con su plan ya resuelto en la misma instancia). */
    public static function company(?int $id): ?Company
    {
        return $id ? self::remember('company:'.$id, fn () => Company::find($id)) : null;
    }
}
