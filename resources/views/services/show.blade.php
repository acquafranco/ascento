<x-services-layout :title="$profile->public_name" :description="\Illuminate\Support\Str::limit($profile->headline ?: $profile->description, 155)">
    <x-slot:hero>
        <div class="p-hero">
            <p style="margin-bottom:4px"><a href="{{ route('services.index') }}" style="color:#C9CCD4">← Profesionales y servicios</a></p>
            <h1>{{ $profile->public_name }}</h1>
            <p>{{ $profile->kindLabel() }} · {{ $profile->modalityLabel() }} · {{ $profile->coverageLabel() }}</p>
        </div>
    </x-slot:hero>

    <div class="p-two">
        <div style="display:grid; gap:14px">
            <section class="p-card">
                @if($profile->headline)<p style="font-weight:600; margin:0 0 8px">{{ $profile->headline }}</p>@endif
                <div style="white-space:pre-line; font-size:15px">{{ $profile->description }}</div>
                @if($profile->experience)
                    <h2 style="font-size:15px; margin:14px 0 4px">Experiencia</h2>
                    <div style="white-space:pre-line; font-size:14px">{{ $profile->experience }}</div>
                @endif
            </section>
            <section class="p-card">
                <h2 style="font-size:15px; margin:0 0 8px">Especialidades</h2>
                <div>@foreach($profile->categories as $c)<span class="p-tag o">{{ $c->name }}</span>@endforeach</div>
                @if($profile->services)
                    <h2 style="font-size:15px; margin:12px 0 6px">Servicios</h2>
                    <ul style="margin:0; padding-left:18px; font-size:14px">@foreach($profile->services as $s)<li>{{ $s }}</li>@endforeach</ul>
                @endif
                @if($profile->brands)
                    <h2 style="font-size:15px; margin:12px 0 6px">Marcas y sistemas</h2>
                    <div>@foreach($profile->brands as $b)<span class="p-tag">{{ $b }}</span>@endforeach</div>
                @endif
            </section>
            <p class="p-declared" data-declared-notice>Información declarada por el titular del perfil. Ascento no verificó títulos, matrículas, certificaciones ni experiencia: antes de contratar, pedí la documentación que necesites.</p>
        </div>

        <aside style="display:grid; gap:14px">
            <section class="p-card">
                <h2 style="font-size:15px; margin:0 0 8px">Dónde y cómo trabaja</h2>
                <dl class="p-dl">
                    <dt>Modalidad</dt><dd>{{ $profile->modalityLabel() }}</dd>
                    <dt>Zona</dt><dd>{{ $profile->coverageLabel() }}</dd>
                    @if($profile->website)<dt>Sitio web</dt><dd><a href="{{ $profile->website }}" rel="nofollow noopener ugc" target="_blank" style="color:var(--orange-ink); font-weight:600">{{ parse_url($profile->website, PHP_URL_HOST) ?: $profile->website }}</a></dd>@endif
                    <dt>En Ascento desde</dt><dd>{{ $profile->created_at->translatedFormat('F \d\e Y') }}</dd>
                </dl>
            </section>
            <section class="p-card" data-contact-box>
                <h2 style="font-size:15px; margin:0 0 6px">Contactar</h2>
                <p class="p-muted" style="margin:0 0 10px">Las empresas de mantenimiento con cuenta en Ascento envían una solicitud describiendo lo que necesitan. El profesional responde desde Ascento; cada parte decide qué datos de contacto compartir.</p>
                @if($canRequest)
                    <a class="p-btn" href="/admin/profesionales?perfil={{ $profile->slug }}" data-contact-cta>Enviar solicitud</a>
                @elseif($loggedIn)
                    <p class="p-muted" style="margin:0">Para enviar solicitudes tenés que ingresar con una cuenta de empresa de mantenimiento con permiso para pedir repuestos y servicios.</p>
                @else
                    <a class="p-btn" href="{{ route('login') }}" data-contact-cta>Ingresar con mi empresa para contactar</a>
                    <p class="p-muted" style="margin:8px 0 0">Después de ingresar, volvé a este perfil y tocá «Enviar solicitud».</p>
                    <p class="p-muted" style="margin:8px 0 0">¿Tu empresa todavía no usa Ascento? <a href="{{ route('register') }}" style="color:var(--orange-ink); font-weight:600">Creá una cuenta</a>.</p>
                @endif
            </section>
        </aside>
    </div>
</x-services-layout>
