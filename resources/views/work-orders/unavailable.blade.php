<x-app-layout>

<div class="max-w-xl mx-auto px-4 pt-16 pb-32 text-center">

    <div class="text-5xl" aria-hidden="true">📭</div>

    <h1 class="mt-4 text-2xl font-black text-slate-800">{{ $title }}</h1>

    <p class="mt-2 text-slate-500">{{ $message }}</p>

    <a
        href="{{ route('work-orders.index', ['company' => auth()->user()->company->slug]) }}"
        class="mt-8 inline-flex rounded-2xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700"
    >
        Ver mis órdenes
    </a>

</div>

</x-app-layout>
