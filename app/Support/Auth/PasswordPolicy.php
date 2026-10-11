<?php

namespace App\Support\Auth;

use App\Rules\NotCommonPassword;
use Illuminate\Validation\Rules\Password;

/**
 * Política única de contraseñas para todos los lugares donde se crean o
 * cambian: registro de empresas, alta y edición de usuarios en el panel,
 * activación del portal, recuperación y cambio desde el perfil.
 *
 * - Al menos 10 caracteres, con letras y números (máximo 72: límite de bcrypt).
 * - Ni comunes (lista local resources/data/common-passwords.txt) ni con el
 *   nombre o el correo de la persona.
 * - Solo al crear o cambiar: las contraseñas actuales siguen funcionando
 *   (el ingreso nunca valida la política).
 * - Sin servicios externos (no se usa uncompromised(): consulta una API).
 */
final class PasswordPolicy
{
    public const MIN = 10;

    public const MAX = 72;

    public const HINT = 'Al menos 10 caracteres, con letras y números. Evitá contraseñas comunes, tu nombre o tu correo.';

    public static function defaults(): Password
    {
        return Password::min(self::MIN)->max(self::MAX)->letters()->numbers();
    }

    /**
     * Reglas para una contraseña nueva.
     *
     * @param  array<int, ?string>  $personal  nombre, correo, empresa… de la persona
     * @return list<mixed>
     */
    public static function rules(array $personal = [], bool $required = true, bool $confirmed = true): array
    {
        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'string',
            $confirmed ? 'confirmed' : null,
            Password::defaults(),
            new NotCommonPassword($personal),
        ]));
    }
}
