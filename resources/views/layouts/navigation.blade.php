<nav class="hidden lg:block sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-[#14171C]/10">
    <div class="max-w-7xl mx-auto px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">

            <div class="flex items-center gap-8">
                <a href="{{ route('dashboard', ['company' => auth()->user()->company->slug]) }}" class="flex items-center gap-2.5 shrink-0">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#12151C] text-[#FF6A1A]">
                        <x-application-logo class="h-4 w-4" />
                    </span>
                    <span class="font-semibold text-[17px] tracking-tight text-[#14171C]">Ascento</span>
                </a>

                <div class="flex items-center gap-1">
                    <x-nav-link :href="route('dashboard', ['company' => auth()->user()->company->slug])" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                </div>
            </div>

            <div class="flex items-center">
                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2.5 pl-1.5 pr-3 py-1.5 rounded-full border border-[#14171C]/10 hover:border-[#14171C]/20 hover:bg-[#14171C]/[0.02] transition-colors">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#FFE8D6] text-[#C24800] text-xs font-bold uppercase">
                                {{ Str::of(Auth::user()->name)->substr(0, 1) }}
                            </span>
                            <span class="text-sm font-medium text-[#14171C]/80">
                                {{ Str::of(Auth::user()->name)->before(' ') }}
                            </span>
                            <svg class="h-3.5 w-3.5 text-[#14171C]/40" viewBox="0 0 16 16" fill="none">
                                <path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-3 border-b border-[#14171C]/10">
                            <div class="text-sm font-semibold text-[#14171C]">{{ Auth::user()->name }}</div>
                            <div class="text-xs text-[#14171C]/45 truncate">{{ Auth::user()->email }}</div>
                        </div>

                        <x-dropdown-link :href="route('profile.edit', ['company' => auth()->user()->company->slug])">
                            {{ __('Mi perfil') }}
                        </x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Cerrar sesión') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>
</nav>
