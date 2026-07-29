@props(['value'])

<label {{ $attributes->merge(['class' => 'block mb-1.5 text-sm font-semibold text-ink/70']) }}>
    {{ $value ?? $slot }}
</label>
