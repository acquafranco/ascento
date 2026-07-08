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

            if (delta > 10 && current > 50) {
                this.showNav = false;
            }

            if (delta < -10) {
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
    class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg z-50 transition-all duration-300"
>
    <div class="grid grid-cols-4 py-3">

        <a href="{{ route('dashboard') }}" class="text-center">
            <div class="text-2xl">🏠</div>
            <div class="text-xs">Inicio</div>
        </a>

        <a href="/work-orders" class="text-center">
            <div class="text-2xl">🧰</div>
            <div class="text-xs">Trabajos</div>
        </a>

        <a href="/buildings" class="text-center">
            <div class="text-2xl">🏢</div>
            <div class="text-xs">Edificios</div>
        </a>

        <!-- BOTÓN MENU -->
        <button
            x-ref="button"
            @click="menu = !menu"
            class="flex items-center justify-center"
        >
            <div class="w-14 h-14 rounded-2xl bg-slate-100 shadow flex items-center justify-center hover:bg-slate-200 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </div>
        </button>

    </div>

    <!-- MENU DESPLEGABLE -->
    <div
        x-show="menu"
        x-transition
        x-ref="menu"
        class="absolute bottom-20 right-4 bg-white rounded-2xl shadow-xl border p-3 w-56"
    >

        <div class="font-bold border-b pb-2 mb-2">
            {{ auth()->user()->name }}
        </div>

        <a href="{{ route('profile.edit') }}" class="block py-2">
            👤 Perfil
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button class="block w-full text-left py-2 text-red-600">
                🚪 Cerrar sesión
            </button>
        </form>

    </div>
</div>
