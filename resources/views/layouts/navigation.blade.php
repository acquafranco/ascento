@php
    ['main' => $navMain, 'more' => $navMore, 'unread' => $unread, 'slug' => $slug] = \App\Support\TechNav::get();
    $navIcon = fn (string $name) => \App\Support\TechNav::icon($name);
    $isTech = auth()->user()->role === 'technician';
@endphp

{{-- Barra superior: en escritorio, todas las secciones; en el celular, marca y avisos. --}}
<nav class="t-nav-top sticky top-0 z-40 bg-ink text-white" aria-label="Principal">
    <div class="mx-auto flex h-14 max-w-6xl items-center gap-6 px-4 lg:h-16 lg:px-8">
        <a href="{{ route('dashboard', ['company' => $slug]) }}" class="flex shrink-0 items-center gap-2.5">
            <img src="{{ asset('images/brand/logo-128.png') }}" alt="" class="h-8 w-8 rounded-lg">
            <span class="leading-tight">
                <span class="block text-[15px] font-bold tracking-tight">Ascento</span>
                <span class="block max-w-[11rem] truncate text-[11px] text-white/55">{{ auth()->user()->company?->name }}</span>
            </span>
        </a>

        @if($isTech)
            <div class="hidden items-center gap-1 lg:flex">
                @foreach([...$navMain, ...$navMore] as $item)
                    <a href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif
                       class="rounded-lg px-3 py-2 text-sm font-semibold transition {{ $item['active'] ? 'bg-white/10 text-white' : 'text-white/65 hover:bg-white/5 hover:text-white' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        @else
            <a href="/admin" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-white/70 hover:text-white lg:block">Volver al panel</a>
        @endif

        <div class="ml-auto flex items-center gap-2">
            @if($isTech)
                <a href="{{ route('notifications.index') }}" aria-label="Avisos{{ $unread ? ' ('.$unread.' sin leer)' : '' }}"
                   class="relative inline-flex h-10 w-10 items-center justify-center rounded-full text-white/80 hover:bg-white/10 hover:text-white {{ request()->routeIs('notifications.*') ? 'bg-white/10 text-white' : '' }}">
                    <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon('bell') !!}</svg>
                    <span data-unread-count @if($unread === 0) hidden @endif
                          class="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-brand px-1 text-center text-[11px] font-bold leading-[18px] text-ink">{{ $unread > 99 ? '99+' : $unread }}</span>
                </a>
            @endif

            <div class="hidden lg:block">
                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button type="button" class="flex items-center gap-2 rounded-full py-1 pl-1 pr-3 text-sm font-semibold text-white/85 hover:bg-white/10" aria-label="Mi cuenta">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-xs font-bold uppercase text-ink">{{ Str::of(auth()->user()->name)->substr(0, 1) }}</span>
                            {{ Str::of(auth()->user()->name)->before(' ') }}
                            <svg class="h-3.5 w-3.5 text-white/50" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="border-b border-ink-100 px-4 py-3">
                            <div class="text-sm font-semibold text-ink">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-ink-500">{{ auth()->user()->email }}</div>
                        </div>
                        <x-dropdown-link :href="route('profile.edit', ['company' => $slug])">Mi perfil</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Cerrar sesión</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>
</nav>
