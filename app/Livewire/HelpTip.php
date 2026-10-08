<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Help\HelpTopics;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tarjeta de ayuda contextual. Se muestra hasta que el usuario la descarta;
 * después no vuelve a aparecer sola (aunque cierre sesión o cambie de
 * página). Se reinicia desde "Mi empresa → Ayuda".
 *
 * El estado es del usuario autenticado: no se recibe ningún id del navegador.
 */
class HelpTip extends Component
{
    #[Locked]
    public string $key = '';

    public bool $dismissed = false;

    public function mount(string $key): void
    {
        $this->key = HelpTopics::exists($key) ? $key : '';
    }

    public function dismiss(): void
    {
        $user = auth()->user();

        if ($user instanceof User && $user->canUseOnboarding() && $this->key !== '') {
            $user->dismissHelp($this->key);
        }

        $this->dismissed = true;
    }

    public function render()
    {
        $user = auth()->user();

        $visible = $user instanceof User
            && $this->key !== ''
            && ! $this->dismissed
            && $user->canUseOnboarding()
            // Mientras corre la guía de bienvenida, no se apilan ayudas.
            && ! $user->shouldAutoStartOnboarding()
            && ! $user->hasSeenHelp($this->key);

        return view('livewire.help-tip', [
            'visible' => $visible,
            'topic' => $visible ? HelpTopics::get($this->key) : null,
        ]);
    }
}
