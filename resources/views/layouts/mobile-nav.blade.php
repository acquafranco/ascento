<div
    x-data="{
        menu: false,
        showNav: true,
        lastScroll: 0,

        fixedPage: {{ request()->routeIs('dashboard') ? 'true' : 'false' }},

        init() {

            if (this.fixedPage) {
                this.showNav = true;
                return;
            }

            window.addEventListener('scroll', () => {

                const current = window.scrollY;
                const delta = current - this.lastScroll;

                if (delta > 15 && current > 60) {
                    this.showNav = false;
                }

                if (delta < -2) {
                    this.showNav = true;
                }

                this.lastScroll = current;
            });

            window.addEventListener('click', (e) => {
                if (
                    this.menu &&
                    !this.$refs.menu.contains(e.target) &&
                    !this.$refs.button.contains(e.target)
                ) {
                    this.menu = false;
                }
            });
        }
    }"
    :style="fixedPage
    ? 'transform:translateY(0)'
    : (showNav
        ? 'transform:translateY(0)'
        : 'transform:translateY(100%)')"
    class="lg:hidden fixed bottom-0 left-0 right-0 z-50 transition-all duration-300 mx-3 mb-3"
>
    <div style="background-color:rgba(247,247,244,0.55); backdrop-filter:saturate(160%) blur(2px); -webkit-backdrop-filter:saturate(160%) blur(2px);"
         class="rounded-3xl border border-black/[0.06] shadow-[0_8px_30px_-8px_rgba(20,23,28,0.22)]">
    <div class="grid grid-cols-4 py-2">

        <a href="{{ route('dashboard', [
            'company' => auth()->user()->company->slug
        ]) }}" class="flex flex-col items-center justify-center gap-1 text-center group">
            <svg class="w-6 h-6 group-active:scale-90 transition-all duration-150" style="color:#FF6A1A" viewBox="0 0 24 24" fill="none">
                <path d="M4 11L12 4L20 11V19A1 1 0 0 1 19 20H15V14H9V20H5A1 1 0 0 1 4 19V11Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
            </svg>
            <div class="text-[11px] font-medium text-[#14171C]/70">Inicio</div>
        </a>

        <a href="{{ route('work-orders.index', [
            'company' => auth()->user()->company->slug
        ]) }}" class="flex flex-col items-center justify-center gap-1 text-center group">
            <svg class="w-6 h-6 group-active:scale-90 transition-all duration-150" style="color:#FF6A1A" viewBox="0 0 24 24" fill="none">
                <path d="M14.5 3.5L20.5 9.5M17 3L21 7L8 20H4V16L17 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
            </svg>
            <div class="text-[11px] font-medium text-[#14171C]/70">Trabajos</div>
        </a>

        <a href="{{ route('buildings.index', [
        'company' => auth()->user()->company->slug,
        'date' => now()->format('Y-m-d')
        ]) }}"  class="flex flex-col items-center justify-center gap-1 text-center group">
            <svg class="w-6 h-6 group-active:scale-90 transition-all duration-150" style="color:#FF6A1A" viewBox="0 0 24 24" fill="none">
                <path d="M4 21V6L12 3L20 6V21H4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                <path d="M10 21V15H14V21" stroke="currentColor" stroke-width="1.7"/>
            </svg>
            <div class="text-[11px] font-medium text-[#14171C]/70">Edificios</div>
        </a>

        <!-- BOTÓN MENU -->
        <button
            x-ref="button"
            @click="menu = !menu"
            class="flex flex-col items-center justify-center gap-1 text-center group"
        >
            <svg xmlns="http://www.w3.org/2000/svg"
                 class="w-6 h-6 group-active:scale-90 transition-all duration-150"
                 style="color:#FF6A1A"
                 fill="none"
                 viewBox="0 0 24 24"
                 stroke="currentColor">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 6h16M4 12h16M4 18h16"
                />
            </svg>
            <div class="text-[11px] font-medium text-[#14171C]/70">Menú</div>
        </button>

    </div>
    </div>

    <!-- MENU DESPLEGABLE -->
    <div
        x-show="menu"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95 translate-y-2"
        x-ref="menu"
        class="absolute bottom-20 right-4 bg-white rounded-3xl shadow-[0_20px_45px_-12px_rgba(20,23,28,0.32)] border border-black/[0.08] p-3 w-56"
    >

        <div class="flex items-center gap-2.5 border-b border-black/[0.06] pb-3 mb-2 px-1">
            <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold uppercase shrink-0" style="background-color:#12151C; color:#FF6A1A;">
                {{ Str::of(auth()->user()->name)->substr(0, 1) }}
            </span>
            <span class="font-semibold text-sm text-[#14171C] truncate">
                {{ auth()->user()->name }}
            </span>
        </div>

        <a href="{{ route('profile.edit', [
            'company' => auth()->user()->company->slug,
        ]) }}" class="flex items-center gap-3 py-2.5 px-2 rounded-xl text-sm text-[#14171C]/75 hover:bg-black/[0.05] hover:text-[#14171C] transition-colors duration-150">
            <svg class="w-[18px] h-[18px] text-[#14171C]/50" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/>
                <path d="M5 20C6 16.5 8.7 15 12 15C15.3 15 18 16.5 19 20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
            Perfil
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button class="w-full flex items-center gap-3 py-2.5 px-2 rounded-xl text-left text-sm font-medium text-red-500 hover:bg-red-50 transition-colors duration-150">
                <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none">
                    <path d="M9 21H5A2 2 0 0 1 3 19V5A2 2 0 0 1 5 3H9M16 17L21 12L16 7M21 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Cerrar sesión
            </button>
        </form>

    </div>
</div>
