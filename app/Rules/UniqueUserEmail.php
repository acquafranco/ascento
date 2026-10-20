<?php

namespace App\Rules;

use App\Support\Auth\UserEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El correo no lo usa otra cuenta de Ascento (de cualquier rol o empresa,
 * también desactivada), comparando sin mayúsculas ni espacios alrededor.
 * La misma regla en todos los formularios que crean o cambian un correo.
 */
class UniqueUserEmail implements ValidationRule
{
    public function __construct(private ?int $exceptUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && UserEmail::taken($value, $this->exceptUserId)) {
            $fail(UserEmail::TAKEN_MESSAGE);
        }
    }
}
