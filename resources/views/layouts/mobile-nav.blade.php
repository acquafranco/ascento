{{--
    Barra inferior del celular: siempre visible (no se esconde al hacer
    scroll), con las 4 secciones de trabajo y "Más" para el resto. Respeta el
    área segura del iPhone. El contenido deja lugar abajo (.t-page).
--}}
@php
    ['main' => $navMain, 'more' => $navMore, 'unread' => $unread, 'slug' => $slug] = \App\Support\TechNav::get();
    $navIcon = fn (string $name) => \App\Support\TechNav::icon($name);
    $moreActive = collect($navMore)->contains('active', true) || request()->routeIs('notifications.*', 'profile.*');
@endphp

<div x-data="{ more: false }" @keydown.escape.window="more = false" class="lg:hidden">
    <nav class="t-nav-bottom fixed inset-x-0 bottom-0 z-50 border-t border-ink-100 bg-white/95 backdrop-blur"
         style="padding-bottom: env(safe-area-inset-bottom);" aria-label="Secciones">
        {{-- Una columna por sección principal + «Más». --}}
        <div class="mx-auto grid max-w-lg" style="grid-template-columns: repeat({{ count($navMain) + 1 }}, minmax(0, 1fr));">
            @foreach($navMain as $item)
                <a href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif
                   class="relative flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-semibold {{ $item['active'] ? 'text-ink' : 'text-ink-500' }}">
                    @if($item['active'])<span class="absolute inset-x-5 top-0 h-[3px] rounded-b-full bg-brand"></span>@endif
                    <svg class="h-6 w-6 {{ $item['active'] ? 'text-brand' : '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon($item['icon']) !!}</svg>
                    {{ $item['label'] }}
                </a>
            @endforeach
            <button type="button" @click="more = !more" :aria-expanded="more" aria-controls="t-more"
                    class="relative flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-semibold {{ $moreActive ? 'text-ink' : 'text-ink-500' }}">
                @if($moreActive)<span class="absolute inset-x-5 top-0 h-[3px] rounded-b-full bg-brand"></span>@endif
                <span class="relative">
                    <svg class="h-6 w-6 {{ $moreActive ? 'text-brand' : '' }}" viewBox="0 0 24 24" fill="currentColor">{!! $navIcon('more') !!}</svg>
                    <span data-unread-count @if($unread === 0) hidden @endif
                          class="absolute -right-3 -top-1.5 min-w-[18px] rounded-full bg-brand px-1 text-center text-[11px] font-bold leading-[18px] text-ink">{{ $unread > 99 ? '99+' : $unread }}</span>
                </span>
                Más
            </button>
        </div>
    </nav>

    {{-- Menú "Más": hoja desde abajo, se cierra tocando afuera o con Escape. --}}
    <div x-cloak x-show="more" x-transition.opacity class="fixed inset-0 z-[55] bg-ink/40" @click="more = false"></div>
    <div id="t-more" x-cloak x-show="more"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         class="fixed inset-x-0 bottom-0 z-[60] rounded-t-3xl bg-white px-3 pt-2 shadow-2xl"
         style="padding-bottom: calc(env(safe-area-inset-bottom) + 12px);" role="dialog" aria-label="Más opciones">
        <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-ink-100"></div>
        <div class="flex items-center gap-3 border-b border-ink-100 px-2 pb-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-ink text-sm font-bold uppercase text-brand">{{ Str::of(auth()->user()->name)->substr(0, 1) }}</span>
            <span class="min-w-0">
                <span class="block truncate font-semibold text-ink">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs text-ink-500">{{ auth()->user()->company?->name }}</span>
            </span>
        </div>
        <div class="py-1">
            @foreach($navMore as $item)
                <a href="{{ $item['href'] }}" class="flex min-h-[52px] items-center gap-3 rounded-xl px-2 text-[15px] font-semibold {{ $item['active'] ? 'bg-brand-50 text-ink' : 'text-ink-700 active:bg-ink-50' }}">
                    <svg class="h-[22px] w-[22px] text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon($item['icon']) !!}</svg>
                    {{ $item['label'] }}
                </a>
            @endforeach
            @if(auth()->user()->role === 'technician')
                <a href="{{ route('notifications.index') }}" class="flex min-h-[52px] items-center gap-3 rounded-xl px-2 text-[15px] font-semibold text-ink-700 active:bg-ink-50">
                    <svg class="h-[22px] w-[22px] text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon('bell') !!}</svg>
                    Avisos
                    <span data-unread-count @if($unread === 0) hidden @endif class="ml-auto min-w-[20px] rounded-full bg-brand px-1.5 text-center text-xs font-bold leading-5 text-ink">{{ $unread > 99 ? '99+' : $unread }}</span>
                </a>
            @endif
            <a href="{{ route('profile.edit', ['company' => $slug]) }}" class="flex min-h-[52px] items-center gap-3 rounded-xl px-2 text-[15px] font-semibold text-ink-700 active:bg-ink-50">
                <svg class="h-[22px] w-[22px] text-ink-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon('user') !!}</svg>
                Mi perfil
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="flex min-h-[52px] w-full items-center gap-3 rounded-xl px-2 text-left text-[15px] font-semibold text-red-600 active:bg-red-50">
                    <svg class="h-[22px] w-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $navIcon('logout') !!}</svg>
                    Cerrar sesión
                </button>
            </form>
        </div>
    </div>
</div>
