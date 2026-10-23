{{-- ============ MARKETPLACE DE REPUESTOS (complemento de la gestión) ============ --}}
{{-- Sección adicional de la Welcome: presenta el marketplace sin quitarle el lugar a la gestión,
     que sigue siendo el producto principal. Las capturas son del marketplace real con datos
     ficticios (docs/marketplace-comercial.md, «Capturas»). El detalle está en /marketplace. --}}
@php
    $mkFree = \App\Models\Marketplace\MarketplaceSettings::current()->freeLimit();
@endphp
<section id="marketplace" class="relative overflow-hidden border-t border-ink/10 bg-white py-20 sm:py-28" data-marketplace-teaser>
    <div class="pointer-events-none absolute inset-0 [background:radial-gradient(45%_55%_at_100%_100%,rgba(255,102,0,0.07),transparent_60%)]" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-5 sm:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-[0.95fr_1.05fr] lg:gap-16">
            <div data-reveal>
                <span class="font-mono text-xs uppercase tracking-widest text-amber-600">También en Ascento · Marketplace de repuestos</span>
                <h2 class="mt-3 font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                    Los repuestos que necesitás, más cerca.
                    <span class="text-ink/45">Las empresas que buscan tus productos, en un mismo lugar.</span>
                </h2>
                <p class="mt-5 max-w-xl text-base leading-relaxed text-ink/65">
                    Cuando un ascensor espera una pieza, cada llamado cuenta. Por eso Ascento suma un marketplace de repuestos que funciona junto a la gestión de tu empresa.
                </p>

                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <article class="rounded-2xl border border-ink/10 bg-paper p-5" data-marketplace-companies>
                        <h3 class="flex items-center gap-2.5 font-display text-[15px] font-semibold">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600" aria-hidden="true">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M7 12A5 5 0 1 0 7 2a5 5 0 0 0 0 10ZM14 14l-3.5-3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </span>
                            Para empresas de ascensores
                        </h3>
                        <p class="mt-3 text-sm leading-relaxed text-ink/65">Encontrá repuestos, explorá los catálogos de proveedores, compará opciones y pedí cotizaciones desde tu panel.</p>
                    </article>
                    <article class="rounded-2xl border border-ink/10 bg-paper p-5" data-marketplace-suppliers>
                        <h3 class="flex items-center gap-2.5 font-display text-[15px] font-semibold">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600" aria-hidden="true">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M2.5 5.5 8 2.5l5.5 3v5L8 13.5l-5.5-3v-5ZM2.5 5.5 8 8.5l5.5-3M8 8.5v5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                            </span>
                            Para proveedores
                        </h3>
                        <p class="mt-3 text-sm leading-relaxed text-ink/65">Publicá tu catálogo con fotos, mostrá tus productos y llegá a empresas del sector. Gratis hasta {{ $mkFree }} productos publicados.</p>
                    </article>
                </div>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <a href="{{ route('marketplace') }}" data-marketplace-cta
                       class="inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-full bg-amber-500 px-6 py-3.5 font-semibold text-graphite transition-all duration-200 hover:-translate-y-0.5 hover:bg-amber-400">
                        Conocé el marketplace
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <p class="text-xs leading-relaxed text-ink/45 sm:max-w-xs">Ascento no cobra comisiones por las ventas: el pago y la entrega se acuerdan con cada proveedor.</p>
                </div>
            </div>

            {{-- Vista previa: capturas del marketplace real (catálogo y ficha en el celular), datos de ejemplo. --}}
            <figure data-reveal style="transition-delay:.1s" class="relative pb-6 sm:pr-10">
                <div class="overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-cardHover">
                    <div class="flex items-center gap-1.5 border-b border-ink/10 bg-paper px-4 py-2.5" aria-hidden="true">
                        <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                        <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                        <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                        <span class="ml-2 font-mono text-[10px] text-ink/40">ascento.online/admin/repuestos</span>
                    </div>
                    <img src="{{ asset('images/marketplace/catalogo.jpg') }}" alt="Catálogo de repuestos de Ascento: buscador, filtros por rubro, marca, disponibilidad y precio, y tarjetas de productos"
                         width="1080" height="860" loading="lazy" decoding="async" class="block aspect-[1080/700] w-full object-cover object-top">
                </div>
                <div class="absolute -bottom-2 right-0 hidden w-[30%] max-w-[180px] overflow-hidden rounded-[1.4rem] border-4 border-graphite bg-graphite shadow-cardHover sm:block">
                    <img src="{{ asset('images/marketplace/ficha-movil.jpg') }}" alt="Ficha de un repuesto en el celular, con galería de fotos"
                         width="780" height="1440" loading="lazy" decoding="async" class="block aspect-[9/16] w-full rounded-[1.1rem] object-cover object-top">
                </div>
                <figcaption class="mt-4 text-xs text-ink/40 sm:mt-6">Capturas del marketplace real de Ascento con datos de ejemplo.</figcaption>
            </figure>
        </div>
    </div>
</section>
