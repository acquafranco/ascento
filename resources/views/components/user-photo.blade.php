{{-- Foto de perfil (Etapa 8.2) o, si no hay, las iniciales. --}}
@props(['user', 'size' => 40, 'src' => null])
@php $src ??= $user->photoUrl(); @endphp
@if($src)
    <img src="{{ $src }}" alt="" width="{{ $size }}" height="{{ $size }}" loading="lazy" data-user-photo
         {{ $attributes->merge(['style' => "width:{$size}px;height:{$size}px;border-radius:9999px;object-fit:cover;flex:none"]) }}>
@else
    <span aria-hidden="true" data-user-initials
          {{ $attributes->merge(['style' => "width:{$size}px;height:{$size}px;border-radius:9999px;flex:none;display:inline-flex;align-items:center;justify-content:center;background:#fde68a;color:#78350f;font-weight:600;font-size:".round($size * 0.38)."px"]) }}>{{ $user->initials() }}</span>
@endif
