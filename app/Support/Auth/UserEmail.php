<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Un correo = una sola cuenta de Ascento, sea cual sea el rol (dueño,
 * administrativo, técnico, portal del cliente, SuperAdmin) o la empresa.
 *
 * - normalize(): la forma única de guardar y comparar un correo (sin espacios
 *   alrededor, en minúsculas). La aplica User al guardar.
 * - taken(): ¿ya hay una cuenta con ese correo? Incluye las desactivadas (su
 *   correo sigue siendo suyo: si se reactiva, vuelve a entrar con él).
 * - La base lo garantiza además con un índice único sobre users.email_key
 *   (el correo normalizado): dos pedidos simultáneos no crean dos cuentas.
 *
 * El mensaje no dice de quién es el correo (rol, empresa ni cliente).
 */
final class UserEmail
{
    public const TAKEN_MESSAGE = 'Este correo electrónico ya está registrado en Ascento. Cada correo puede estar asociado a una sola cuenta. Verificá la dirección o utilizá el proceso de acceso correspondiente.';

    public static function normalize(?string $email): string
    {
        // trim() de PHP no saca espacios Unicode (p. ej. el que se cuela al copiar y pegar).
        return mb_strtolower((string) preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', (string) $email));
    }

    /** ¿Otra cuenta (también desactivada) ya usa este correo? */
    public static function taken(?string $email, ?int $exceptUserId = null): bool
    {
        return self::owner($email, $exceptUserId) !== null;
    }

    /** La cuenta que usa este correo (también desactivada), o null. */
    public static function owner(?string $email, ?int $exceptUserId = null): ?User
    {
        $email = self::normalize($email);
        if ($email === '') {
            return null;
        }

        // email_key = LOWER(TRIM(email)), columna generada con índice único.
        return User::withTrashed()
            ->where('email_key', $email)
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->first();
    }

    /** ¿Este choque de la base es por el correo de un usuario? */
    public static function isConflict(UniqueConstraintViolationException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'users') && (str_contains($message, 'email_key') || str_contains($message, 'users_email_unique') || str_contains($message, 'users.email'));
    }

    /**
     * Ejecuta una escritura de usuario y convierte el choque del índice único
     * (otro pedido registró el mismo correo al mismo tiempo) en el error de
     * validación de siempre, sobre el campo indicado.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function guard(callable $callback, string $field = 'email'): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $e) {
            if (! self::isConflict($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([$field => self::TAKEN_MESSAGE]);
        }
    }
}
