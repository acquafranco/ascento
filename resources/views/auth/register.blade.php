<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Compania -->
        <div>

                <!-- Name -->
            <div>
                <x-input-label for="name" :value="__('Nombre Completo')" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <!-- Email Address -->
            <div class="mt-4">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-input-label for="company_name" value="Nombre de Empresa" />
                <x-text-input
                    id="company_name"
                    class="block mt-1 w-full"
                    type="text"
                    name="company_name"
                    :value="old('company_name')"
                    required/>
            <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
        </div>
        <!-- Razón social -->
        <div class="mt-4">
            <x-input-label for="business_name" value="Razón social" />
            <x-text-input
                id="business_name"
                class="block mt-1 w-full"
                type="text"
                name="business_name"
                :value="old('business_name')"
            />
            <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
        </div>

        <!-- CUIT -->
        <div class="mt-4">
            <x-input-label for="cuit" value="CUIT" />
            <x-text-input
                id="cuit"
                class="block mt-1 w-full"
                type="text"
                name="cuit"
                :value="old('cuit')"
            />
            <x-input-error :messages="$errors->get('cuit')" class="mt-2" />
        </div>

        <!-- Teléfono -->
        <div class="mt-4">
            <x-input-label for="phone" value="Teléfono" />
            <x-text-input
                id="phone"
                class="block mt-1 w-full"
                type="text"
                name="phone"
                :value="old('phone')"
            />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <!-- Dirección -->
        <div class="mt-4">
            <x-input-label for="address" value="Dirección" />
            <x-text-input
                id="address"
                class="block mt-1 w-full"
                type="text"
                name="address"
                :value="old('address')"
            />
            <x-input-error :messages="$errors->get('address')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Contraseña')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Repetir Contraseña')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
