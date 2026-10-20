@props(['tone' => 'warn', 'title' => null])
@php
    $styles = [
        'warn' => 'bg-[#FFF4EC] border-[#FFC9A3] text-[#7A3300]',
        'danger' => 'bg-[#FDECEC] border-[#F5B5B5] text-[#7F1D1D]',
        'info' => 'bg-[#EEF2FC] border-[#C7D3F5] text-[#1E3A8A]',
    ][$tone] ?? '';
@endphp
<div {{ $attributes->merge(['class' => "rounded-2xl border p-5 leading-7 {$styles}"]) }} role="note">
    @if($title)<p class="font-semibold">{{ $title }}</p>@endif
    <div class="{{ $title ? 'mt-2' : '' }} space-y-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">{{ $slot }}</div>
</div>
