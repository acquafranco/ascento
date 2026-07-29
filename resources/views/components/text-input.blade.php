@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full rounded-xl border border-ink/15 bg-white px-4 py-2.5 text-sm text-ink placeholder:text-ink/30 shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100 disabled:bg-ink/5 disabled:text-ink/40']) }}>
