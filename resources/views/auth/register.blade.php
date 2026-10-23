<x-guest-layout>
    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Alta de empresa</span>
    <h1 class="mt-2 font-display font-semibold text-2xl tracking-tight text-ink">Creá tu cuenta</h1>
    <p class="mt-1.5 text-sm text-ink/50">Empezá a organizar tu empresa de ascensores hoy.</p>

    @include('auth.partials.account-type', ['current' => 'company'])

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-6">
        @php $planChoice = $intendedPlan ?? \App\Models\SubscriptionPlan::offered()->firstWhere('slug', old('intended_plan')); @endphp
        @if($planChoice)
            <input type="hidden" name="intended_plan" value="{{ $planChoice->slug }}">
            <p class="rounded-xl border border-amber-500/30 bg-amber-50 px-4 py-3 text-sm text-ink/70" data-register-plan>Elegiste el plan <strong class="text-ink">{{ $planChoice->shortName() }}</strong> ({{ $planChoice->formattedPrice() }}/mes). Empezás con la prueba gratis de {{ \App\Models\SubscriptionPlan::TRIAL_DAYS }} días y lo contratás desde tu cuenta cuando quieras.</p>
        @endif
        @csrf

        <x-input-error :messages="$errors->get('form')" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3" />

        {{-- Campo trampa: una persona no lo ve ni lo completa (ver RegistrationChallenge). --}}
        <div aria-hidden="true" style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;">
            <label for="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}">No completar este campo</label>
            <input type="text" id="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}" name="{{ \App\Support\Auth\RegistrationChallenge::HONEYPOT }}" value="" tabindex="-1" autocomplete="off">
        </div>

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

                {{-- País: solo los habilitados para registrarse (RegistrationCountries). Con uno solo
                     (Argentina, por defecto) no hay selector: el servidor asigna Argentina. --}}
                @php
                    $countryOptions = \App\Support\RegistrationCountries::options();
                    $country = \App\Support\Countries::normalize(old('country', 'AR'));
                    $country = array_key_exists($country, $countryOptions) ? $country : 'AR';
                    $countryInfo = collect(\App\Support\Countries::LATAM)->only(array_keys($countryOptions))->map(fn ($c) => ['tax' => $c[2], 'example' => $c[3], 'region' => $c[1]]);
                @endphp
                @if (count($countryOptions) > 1)
                    <div>
                        <x-input-label for="country" value="País" />
                        <select id="country" name="country" required data-register-country
                            class="w-full rounded-xl border border-ink/15 bg-white px-4 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100">
                            @foreach($countryOptions as $code => $name)
                                <option value="{{ $code }}" @selected($country === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-ink/45">Registro disponible solo para los países de la lista. Que tu país esté habilitado no implica que el pago de la suscripción o la facturación estén disponibles ahí.</p>
                        <x-input-error :messages="$errors->get('country')" class="mt-1.5" />
                    </div>
                @else
                    <p class="text-xs text-ink/45" data-register-home-only>Por ahora el registro está disponible para empresas de Argentina.</p>
                    <x-input-error :messages="$errors->get('country')" class="mt-1.5" />
                @endif

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="cuit" :value="\App\Support\Countries::taxIdLabel($country)" data-register-tax-label />
                        <x-text-input id="cuit" type="text" name="cuit" :value="old('cuit')" required :inputmode="$country === 'AR' ? 'numeric' : 'text'" :placeholder="\App\Support\Countries::taxIdExample($country)" />
                        <x-input-error :messages="$errors->get('cuit')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="phone" value="Teléfono" />
                        <x-text-input id="phone" type="tel" name="phone" :value="old('phone')" required placeholder="11 2345-6789" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="province" :value="\App\Support\Countries::regionLabel($country)" data-register-region-label />
                        {{-- Argentina: la lista de provincias. Otros países: texto libre. Solo se envía el que corresponde. --}}
                        <select id="province" name="province" required data-register-province-select @if($country !== 'AR') hidden disabled @endif
                            class="w-full rounded-xl border border-ink/15 bg-white px-4 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100">
                            <option value="">Elegí la provincia</option>
                            @foreach(\App\Support\Provinces::LIST as $province)
                                <option value="{{ $province }}" @selected(old('province') === $province)>{{ $province }}</option>
                            @endforeach
                        </select>
                        <input id="province_text" type="text" name="province" value="{{ $country !== 'AR' ? old('province') : '' }}" required maxlength="255" data-register-province-text @if($country === 'AR') hidden disabled @endif
                            class="w-full rounded-xl border border-ink/15 bg-white px-4 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100">
                        <x-input-error :messages="$errors->get('province')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="locality" value="Localidad" />
                        <x-text-input id="locality" type="text" name="locality" :value="old('locality')" required placeholder="Lomas de Zamora" />
                        <x-input-error :messages="$errors->get('locality')" class="mt-1.5" />
                    </div>
                </div>

                <p class="text-xs text-ink/40">Razón social, domicilio y demás datos los completás después en "Mi empresa".</p>
                <script>
                    (function () {
                        var info = @json($countryInfo);
                        var country = document.querySelector('[data-register-country]');
                        if (!country) return;
                        var taxLabel = document.querySelector('[data-register-tax-label]');
                        var regionLabel = document.querySelector('[data-register-region-label]');
                        var tax = document.getElementById('cuit');
                        var select = document.querySelector('[data-register-province-select]');
                        var text = document.querySelector('[data-register-province-text]');
                        country.addEventListener('change', function () {
                            var c = info[country.value] || info.AR;
                            var ar = country.value === 'AR';
                            taxLabel.textContent = c.tax;
                            regionLabel.textContent = c.region;
                            tax.placeholder = c.example;
                            tax.inputMode = ar ? 'numeric' : 'text';
                            select.hidden = select.disabled = !ar;
                            text.hidden = text.disabled = ar;
                        });
                    })();
                </script>
            </div>
        </div>

        {{-- Contraseña --}}
        <div>
            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide mb-3">Seguridad</div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="password" :value="__('Contraseña')" />
                    <x-password-input id="password" name="password" required autocomplete="new-password" aria-describedby="password-hint" />
                    <p id="password-hint" class="mt-1.5 text-xs text-ink/50">{{ \App\Support\Auth\PasswordPolicy::HINT }}</p>
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" :value="__('Repetir contraseña')" />
                    <x-password-input id="password_confirmation" name="password_confirmation" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
                </div>
            </div>
        </div>

        @include('auth.partials.register-challenge')

        {{-- Términos y condiciones --}}
        <div class="pt-2">
            <label for="terms" class="flex items-start gap-3 cursor-pointer">
                <input
                    id="terms"
                    type="checkbox"
                    name="terms"
                    value="1"
                    required
                    class="mt-0.5 h-4 w-4 rounded border-ink/20 text-amber-600 focus:ring-amber-500"
                >

                <span class="text-sm text-ink/50 leading-5">
                    Acepto los
                    <a
                        href="{{ route('legal.terms') }}"
                        target="_blank"
                        class="font-medium text-ink underline underline-offset-2 hover:text-amber-600 transition-colors"
                    >
                        términos y condiciones
                    </a>
                    de uso y leí la
                    <a
                        href="{{ route('legal.privacy') }}"
                        target="_blank"
                        class="font-medium text-ink underline underline-offset-2 hover:text-amber-600 transition-colors"
                    >
                        política de privacidad</a>.
                </span>
            </label>

            <x-input-error :messages="$errors->get('terms')" class="mt-1.5" />
        </div>

        <div class="flex items-center justify-between gap-4 pt-2 border-t border-ink/10">
            <a class="mt-3 py-1 text-sm font-medium text-ink/50 hover:text-ink transition-colors" href="{{ route('login') }}">
                Ya tengo cuenta
            </a>

            <x-primary-button class="mt-4">
                Crear cuenta
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <path d="M3 8H13M13 8L9 4M13 8L9 12"
                          stroke="currentColor"
                          stroke-width="1.6"
                          stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
