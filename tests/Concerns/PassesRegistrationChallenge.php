<?php

namespace Tests\Concerns;

use App\Support\Auth\RegistrationChallenge;

/**
 * Registro de empresas: abre el formulario (arranca el reloj y crea el
 * desafío), espera el tiempo mínimo y devuelve la respuesta correcta, como
 * haría una persona.
 */
trait PassesRegistrationChallenge
{
    protected function registrationProof(): array
    {
        $this->get('/register')->assertOk();
        $challenge = session('register.challenge');
        $this->travel(RegistrationChallenge::MIN_SECONDS + 1)->seconds();

        return ['challenge' => collect($challenge['buttons'])->firstWhere('digit', $challenge['target'])['token']];
    }
}
