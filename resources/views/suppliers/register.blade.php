<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Alta de proveedor</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Vendé repuestos a empresas de ascensores</h1>
    <p class="mt-1.5 text-sm text-ink/50">Publicá tu catálogo y recibí pedidos de cotización de empresas de mantenimiento. Revisamos cada cuenta antes de mostrarla.</p>

    @include('auth.partials.account-type', ['current' => 'supplier'])

    @php
        $country = \App\Support\Countries::normalize(old('country', 'AR'));
        $countryInfo = collect(\App\Support\Countries::LATAM)->map(fn ($c) => ['tax' => $c[2], 'example' => $c[3]]);
        $field = 'w-full rounded-xl border border-ink/15 bg-white px-4 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100';
    @endphp

    <form method="POST" action="{{ route('suppliers.register') }}" class="mt-8 space-y-6" data-supplier-register>
        @csrf
        <x-input-error :messages="$errors->get('form')" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3" />

        <div aria-hidden="true" style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;">
            <label for="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}">No completar este campo</label>
            <input type="text" id="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}" name="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}" value="" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Tus datos</div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Nombre completo" />
                    <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="ventas@tuempresa.com" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>
            </div>
        </div>

        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Tu empresa</div>
            <div class="space-y-4">
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="supplier_name" value="Nombre comercial" />
                        <x-text-input id="supplier_name" type="text" name="supplier_name" :value="old('supplier_name')" required placeholder="Repuestos del Sur" />
                        <x-input-error :messages="$errors->get('supplier_name')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="legal_name" value="Razón social (opcional)" />
                        <x-text-input id="legal_name" type="text" name="legal_name" :value="old('legal_name')" />
                        <x-input-error :messages="$errors->get('legal_name')" class="mt-1.5" />
                    </div>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="country" value="País" />
                        <select id="country" name="country" required data-supplier-country class="{{ $field }}">
                            @foreach(\App\Support\Countries::options() as $code => $name)
                                <option value="{{ $code }}" @selected($country === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('country')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="tax_id" :value="\App\Support\Countries::taxIdLabel($country)" data-supplier-tax-label />
                        <x-text-input id="tax_id" type="text" name="tax_id" :value="old('tax_id')" required :placeholder="\App\Support\Countries::taxIdExample($country)" />
                        <x-input-error :messages="$errors->get('tax_id')" class="mt-1.5" />
                    </div>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="phone" value="Teléfono de ventas" />
                        <x-text-input id="phone" type="tel" name="phone" :value="old('phone')" required />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="coverage" value="Zonas a las que vendés o enviás" />
                        <x-text-input id="coverage" type="text" name="coverage" :value="old('coverage')" required placeholder="CABA y GBA; envíos a todo el país" />
                        <x-input-error :messages="$errors->get('coverage')" class="mt-1.5" />
                    </div>
                </div>
                <fieldset>
                    <legend class="text-sm font-medium text-ink">Rubros</legend>
                    <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                        @foreach(\App\Support\Marketplace\Catalog::CATEGORIES as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-ink/80"><input type="checkbox" name="categories[]" value="{{ $key }}" @checked(in_array($key, (array) old('categories', []), true)) class="h-4 w-4 rounded border-ink/20"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('categories')" class="mt-1.5" />
                </fieldset>
                <div>
                    <x-input-label for="brands" value="Marcas con las que trabajás (opcional)" />
                    <x-text-input id="brands" type="text" name="brands" :value="old('brands')" placeholder="Separadas por coma" />
                    <x-input-error :messages="$errors->get('brands')" class="mt-1.5" />
                </div>
            </div>
        </div>

        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Seguridad</div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="password" value="Contraseña" />
                    <x-password-input id="password" name="password" required autocomplete="new-password" aria-describedby="password-hint" />
                    <p id="password-hint" class="mt-1.5 text-xs text-ink/50">{{ \App\Support\Auth\PasswordPolicy::HINT }}</p>
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" value="Repetir contraseña" />
                    <x-password-input id="password_confirmation" name="password_confirmation" required autocomplete="new-password" />
                </div>
            </div>
        </div>

        @include('auth.partials.register-challenge')

        <div class="pt-2">
            <label for="terms" class="flex items-start gap-3 cursor-pointer">
                <input id="terms" type="checkbox" name="terms" value="1" required class="mt-0.5 h-4 w-4 rounded border-ink/20 text-amber-600 focus:ring-amber-500">
                <span class="text-sm text-ink/50 leading-5">
                    Acepto los <a href="{{ route('legal.terms') }}" target="_blank" class="font-medium text-ink underline underline-offset-2 hover:text-amber-600">términos y condiciones</a>
                    y leí la <a href="{{ route('legal.privacy') }}" target="_blank" class="font-medium text-ink underline underline-offset-2 hover:text-amber-600">política de privacidad</a>.
                    Lo que publique (precios, disponibilidad y compatibilidad) es mi responsabilidad.
                </span>
            </label>
            <x-input-error :messages="$errors->get('terms')" class="mt-1.5" />
        </div>

        <div class="flex items-center justify-between gap-4 pt-2 border-t border-ink/10">
            <a class="mt-3 py-1 text-sm font-medium text-ink/50 hover:text-ink transition-colors" href="{{ route('login') }}">Ya tengo cuenta</a>
            <x-primary-button class="mt-4">Crear cuenta de proveedor</x-primary-button>
        </div>
    </form>
    <script>
        (function () {
            var info = @json($countryInfo);
            var country = document.querySelector('[data-supplier-country]');
            if (!country) return;
            country.addEventListener('change', function () {
                var c = info[country.value] || info.AR;
                document.querySelector('[data-supplier-tax-label]').textContent = c.tax;
                document.getElementById('tax_id').placeholder = c.example;
            });
        })();
    </script>
</x-guest-layout>
