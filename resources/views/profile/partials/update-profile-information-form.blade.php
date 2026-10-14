<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    {{-- Cambio de correo pendiente de confirmación (Etapa 11). --}}
    @if ($user->pending_email)
        <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-gray-800" data-pending-email>
            <p>Pediste cambiar tu correo a <strong>{{ $user->pending_email }}</strong>. Confirmalo con el enlace que te mandamos a esa dirección. Hasta entonces seguís entrando con <strong>{{ $user->email }}</strong>.</p>
            <form method="post" action="{{ route('profile.email.cancel') }}" class="mt-2">
                @csrf
                <button type="submit" class="underline text-sm text-gray-700">Cancelar el cambio</button>
            </form>
        </div>
    @endif
    @if (session('status') === 'email-pending')
        <p class="mt-2 font-medium text-sm text-green-700">Te mandamos un enlace al correo nuevo para confirmarlo.</p>
    @elseif (session('status') === 'email-change-canceled')
        <p class="mt-2 font-medium text-sm text-gray-700">Cancelaste el cambio de correo.</p>
    @endif

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- Solo si cambia el correo (Etapa 11, R8). --}}
        <div>
            <x-input-label for="profile_current_password" value="Contraseña actual (solo si cambiás el correo)" />
            <x-text-input id="profile_current_password" name="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
            <x-input-error class="mt-2" :messages="$errors->get('current_password')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
