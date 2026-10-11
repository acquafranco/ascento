<x-mail::message>
{{-- Saludo --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
# Hola
@endif

{{-- Texto --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Botón --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Firma --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Ascento
@endif

{{-- Enlace por si el botón no funciona en ese cliente de correo --}}
@isset($actionText)
<x-slot:subcopy>
Si el botón "{{ $actionText }}" no funciona, copiá y pegá este enlace en tu navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
