<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 rounded-full bg-graphite px-6 py-2.5 text-sm font-semibold text-white shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:shadow-cardHover focus:outline-none focus-visible:ring-2 focus-visible:ring-rail-500 focus-visible:ring-offset-2 active:translate-y-0 disabled:opacity-50 disabled:pointer-events-none']) }}>
    {{ $slot }}
</button>
