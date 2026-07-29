@props(['active' => false])

@php
$classes = ($active ?? false)
    ? 'inline-flex items-center gap-1.5 rounded-full bg-[#FFE8D6] px-3.5 py-2 text-sm font-semibold text-[#C24800] transition-colors'
    : 'inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-medium text-[#14171C]/55 hover:text-[#14171C] hover:bg-[#14171C]/[0.04] transition-colors';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
