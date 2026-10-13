{{--
    Responsables (Etapa 8.3): técnicos asignados a los edificios del cliente.
    Solo foto, nombre, rol y edificios. El contacto es el de la empresa:
    nunca los datos personales del técnico.
--}}
<section class="p-sec" data-portal-responsibles>
    <div class="p-sec-head"><h2>{{ $title ?? 'Quiénes atienden tus edificios' }}</h2></div>
    @if($responsibles->isEmpty())
        <div class="p-empty">{{ $portalCompany?->name }} todavía no asignó técnicos {{ ($single ?? false) ? 'a este edificio' : 'a tus edificios' }}.</div>
    @else
        <ul class="p-list">
            @foreach($responsibles as $r)
                <li class="p-row" style="justify-content:flex-start" data-responsible="{{ $r['user']->id }}">
                    <x-user-photo :user="$r['user']" :size="44" :src="\App\Support\Portal\PortalResponsibles::photoUrl($r['user'])" />
                    <span style="min-width:0">
                        <strong>{{ $r['user']->name }}</strong>
                        <span class="p-muted"> · {{ implode(' e ', $r['roles']) }}</span>
                        @unless($single ?? false)
                            <br><span class="p-muted">{{ implode(', ', $r['buildings']) }}</span>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
    @if($portalCompany && (filled($portalCompany->phone) || filled($portalCompany->email)))
        <p class="p-muted" style="margin-top:8px" data-portal-contact>
            Para consultas, contactá a {{ $portalCompany->name }}:
            @if(filled($portalCompany->phone))<a class="p-link" href="tel:{{ preg_replace('/[^0-9+]/', '', $portalCompany->phone) }}">{{ $portalCompany->phone }}</a>@endif
            @if(filled($portalCompany->phone) && filled($portalCompany->email)) · @endif
            @if(filled($portalCompany->email))<a class="p-link" href="mailto:{{ $portalCompany->email }}">{{ $portalCompany->email }}</a>@endif
        </p>
    @endif
</section>
