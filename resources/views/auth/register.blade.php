<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Alta de empresa</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Creá tu cuenta</h1>
    <p class="mt-1.5 text-sm text-ink/50">Empezá a organizar tu empresa de ascensores hoy.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-6">
        @csrf

        {{-- Datos de la persona --}}
        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Tus datos</div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" :value="__('Nombre completo')" />
                    <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Juan Pérez" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="vos@empresa.com" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>
            </div>
        </div>

        {{-- Datos de la empresa --}}
        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Datos de la empresa</div>
            <div class="space-y-4">
                <div>
                    <x-input-label for="company_name" value="Nombre de empresa" />
                    <x-text-input id="company_name" type="text" name="company_name" :value="old('company_name')" required placeholder="Ascensores del Sur" />
                    <x-input-error :messages="$errors->get('company_name')" class="mt-1.5" />
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="business_name" value="Razón social" />
                        <x-text-input id="business_name" type="text" name="business_name" :value="old('business_name')" placeholder="Ascensores del Sur S.R.L." />
                        <x-input-error :messages="$errors->get('business_name')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="cuit" value="CUIT" />
                        <x-text-input id="cuit" type="text" name="cuit" :value="old('cuit')" placeholder="30-12345678-9" />
                        <x-input-error :messages="$errors->get('cuit')" class="mt-1.5" />
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="phone" value="Teléfono" />
                        <x-text-input id="phone" type="text" name="phone" :value="old('phone')" placeholder="+54 11 0000-0000" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="address" value="Dirección" />
                        <x-text-input id="address" type="text" name="address" :value="old('address')" placeholder="Av. Siempre Viva 742" />
                        <x-input-error :messages="$errors->get('address')" class="mt-1.5" />
                    </div>
                </div>
            </div>
        </div>

        {{-- Contraseña --}}
        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Seguridad</div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="password" :value="__('Contraseña')" />
                    <x-text-input id="password" type="password" name="password" required autocomplete="new-password" placeholder="••••••••" />
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" :value="__('Repetir contraseña')" />
                    <x-text-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="••••••••" />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 pt-2 border-t border-ink/10">
            <a class="mt-4 text-sm font-medium text-ink/50 hover:text-ink transition-colors" href="{{ route('login') }}">
                Ya tengo cuenta
            </a>

            <x-primary-button class="mt-4">
                Crear cuenta
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
