{{--
    Planilla del mes del técnico: los trabajos hechos, semana por semana. En
    el celular se listan solo los días con trabajo; en escritorio, la semana
    completa. Cada día abre su detalle.
--}}
@php
    $slug = auth()->user()->company->slug;
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Planilla del mes</h1>
        <p class="t-sub">Los trabajos que hiciste o en los que participaste, por semana.</p>
    </header>

    <form method="GET" class="mb-5 grid grid-cols-2 gap-2 sm:max-w-sm">
        <select name="month" class="t-input" aria-label="Mes" onchange="this.form.submit()">
            @foreach(range(1, 12) as $m)
                <option value="{{ $m }}" @selected($month == $m)>{{ ucfirst(\Carbon\Carbon::create()->month($m)->translatedFormat('F')) }}</option>
            @endforeach
        </select>
        <select name="year" class="t-input" aria-label="Año" onchange="this.form.submit()">
            @foreach(range(now()->year - 3, now()->year + 1) as $y)
                <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
            @endforeach
        </select>
    </form>

    <div class="space-y-6">
        @forelse($weeks as $week)
            @php
                $days = collect(range(0, 6))->map(function ($i) use ($week) {
                    $date = $week['start']->copy()->addDays($i);

                    return [
                        'date' => $date,
                        'visits' => $week['visits']->filter(fn ($visit) => \Carbon\Carbon::parse($visit->visited_at)->isSameDay($date))->values(),
                    ];
                });
                $worked = $days->filter(fn ($d) => $d['visits']->isNotEmpty());
            @endphp
            <section>
                <h2 class="t-section mt-0">Semana del {{ $week['start']->translatedFormat('d') }} al {{ $week['end']->translatedFormat('d M') }}</h2>
                @if($worked->isEmpty())
                    <div class="t-empty py-5">Sin trabajos esta semana.</div>
                @else
                    <div class="grid grid-cols-1 gap-2 lg:grid-cols-7">
                        @foreach($days as $day)
                            @php
                                $count = $day['visits']->count();
                                $href = isset($user)
                                    ? route('users.template.day', ['company' => $slug, 'user' => $user->id, 'date' => $day['date']->format('Y-m-d')])
                                    : route('templates.day', ['company' => $slug, 'date' => $day['date']->format('Y-m-d')]);
                            @endphp
                            @if($count)
                                <a href="{{ $href }}" class="t-card block p-3 transition hover:border-ink-300 active:scale-[0.99]">
                                    <div class="flex items-baseline justify-between">
                                        <span class="font-bold text-ink">{{ ucfirst($day['date']->translatedFormat('D d')) }}</span>
                                        <span class="t-chip-done">{{ $count }} {{ $count === 1 ? 'trabajo' : 'trabajos' }}</span>
                                    </div>
                                    <ul class="mt-2 space-y-1.5">
                                        @foreach($day['visits']->take(3) as $visit)
                                            @php
                                                $isMine = (int) $visit->user_id === (int) auth()->id();
                                                $isParticipant = ($visit->participants ?? collect())->contains('id', auth()->id());
                                            @endphp
                                            <li class="text-sm">
                                                <span class="block truncate font-semibold text-ink-700">{{ $visit->building?->name }}</span>
                                                <span class="block truncate text-xs text-ink-500">
                                                    {{ $isMine ? 'Hecho por vos' : ($isParticipant ? 'Participaste con '.$visit->user?->name : 'Hecho por '.$visit->user?->name) }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if($count > 3)<p class="mt-1.5 text-xs font-semibold text-ink-500">y {{ $count - 3 }} más</p>@endif
                                </a>
                            @else
                                <div class="hidden rounded-2xl border border-dashed border-ink-100 p-3 text-sm text-ink-300 lg:block">
                                    <span class="font-semibold">{{ ucfirst($day['date']->translatedFormat('D d')) }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>
        @empty
            <div class="t-empty">
                <p class="font-semibold text-ink">No hay trabajos en este mes</p>
                <p class="mt-1">Cuando firmes remitos, los vas a ver acá día por día.</p>
            </div>
        @endforelse
    </div>
</div>
</x-app-layout>
