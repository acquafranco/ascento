@props(['n', 'title', 'id'])
<section id="{{ $id }}" class="scroll-mt-24">
    <span class="font-mono text-xs tracking-widest text-[#FF6A1A] uppercase">{{ $n }}</span>
    <h2 class="mt-2 font-display text-2xl font-semibold">{{ $title }}</h2>
    <div class="mt-4 space-y-4 text-[#14171C]/75 leading-8 [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:space-y-2 [&_strong]:text-[#14171C] [&_a]:text-[#C24800] [&_a]:font-semibold [&_a:hover]:underline">
        {{ $slot }}
    </div>
</section>
