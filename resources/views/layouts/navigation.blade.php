<nav class="hidden lg:block sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-[#14171C]/10">
    <div class="max-w-7xl mx-auto px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">

            <div class="flex items-center gap-8">
                <a href="{{ route('dashboard', ['company' => auth()->user()->company->slug]) }}" class="flex items-center gap-2.5 shrink-0">
                        <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" class="rounded-md h-7 w-7 object-contain">
                    <span class="font-semibold text-[17px] tracking-tight text-[#14171C]">Ascento</span>
                </a>

                <div class="flex items-center gap-1">
                    <x-nav-link :href="route('dashboard', ['company' => auth()->user()->company->slug])" :active="request()->routeIs('dashboard')">

                    </x-nav-link>
                </div>
            </div>

            <div class="flex items-center gap-3">
                @if(auth()->user()->role === 'technician')
                    @php($unreadCount = auth()->user()->unreadNotifications()->count())
                    <a href="{{ route('notifications.index') }}" aria-label="Avisos" style="position:relative; display:inline-flex; width:40px; height:40px; align-items:center; justify-content:center; border-radius:999px; border:1px solid rgba(20,23,28,0.1);">
                        <svg style="width:20px; height:20px; color:rgba(20,23,28,0.7)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span data-unread-count @if($unreadCount === 0) hidden @endif style="position:absolute; top:-4px; right:-6px; min-width:18px; height:18px; padding:0 5px; border-radius:999px; background:#dc2626; color:#fff; font-size:11px; font-weight:700; line-height:18px; text-align:center;">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    </a>
                @endif
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
