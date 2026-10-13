<x-filament-widgets::widget>
    @include('filament.partials.insights-styles')
    <div class="asc-card" data-staff-notice>
        <p class="asc-q" style="font-weight:600">Tu inicio muestra solo lo de tus áreas</p>
        <p class="asc-c">Todavía no tenés permisos sobre áreas con números para este resumen. Si necesitás ver más, pedíselo al dueño de la cuenta.</p>
        @if($links)
            <p class="asc-small" style="margin:.75rem 0 0">
                @foreach($links as $link)
                    <a href="{{ $link['url'] }}" class="asc-pill" style="margin:.15rem .25rem .15rem 0">{{ $link['label'] }}</a>
                @endforeach
            </p>
        @endif
    </div>
</x-filament-widgets::widget>
