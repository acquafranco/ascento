<?php

namespace App\Support\Access;

use App\Enums\PermissionArea;
use App\Models\User;

/**
 * ¿Puede este usuario ver un dato que sale de estas áreas? (Etapa 6). Lo usan
 * los indicadores y el inicio: un número, una serie o una tarjeta se calcula
 * SOLO si el usuario tiene «ver» en TODAS las áreas de las que sale. El
 * dueño y el SuperAdmin ven todo, como siempre.
 */
final class AreaGate
{
    private function __construct(
        private readonly ?User $user,
        private readonly bool $filtered,
    ) {}

    public static function for(?User $user): self
    {
        return new self($user, Access::isStaff($user));
    }

    public function allows(PermissionArea ...$areas): bool
    {
        if (! $this->filtered) {
            return true;
        }

        foreach ($areas as $area) {
            if (! Access::can($this->user, $area)) {
                return false;
            }
        }

        return true;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function isFiltered(): bool
    {
        return $this->filtered;
    }
}
