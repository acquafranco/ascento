<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 pb-32 lg:mt-16">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-[#14171C]">Avisos</h1>
                <p class="text-sm text-[#14171C]/55">Trabajos asignados, cambios y recordatorios.</p>
            </div>
            @if($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium px-3 py-2 rounded-xl border border-[#14171C]/10 bg-white hover:bg-[#14171C]/[0.03]">Marcar todos como leídos</button>
                </form>
            @endif
        </div>

        @if(session('status'))
            <p class="mt-3 text-sm text-emerald-700">{{ session('status') }}</p>
        @endif

        <div class="mt-5 space-y-2">
            @forelse($notifications as $notification)
                <a href="{{ route('notifications.open', $notification->id) }}"
                   class="block rounded-2xl border bg-white px-4 py-3 {{ $notification->read_at ? 'border-[#14171C]/10' : 'border-[#FF6A1A]/40' }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-[15px] {{ $notification->read_at ? 'text-[#14171C]/80' : 'font-semibold text-[#14171C]' }}">{{ $notification->data['title'] ?? 'Aviso' }}</p>
                        <span class="shrink-0 text-xs text-[#14171C]/45">{{ $notification->created_at->format('d/m H:i') }}</span>
                    </div>
                    @if(filled($notification->data['body'] ?? null))
                        <p class="mt-0.5 text-sm text-[#14171C]/60 whitespace-pre-line">{{ $notification->data['body'] }}</p>
                    @endif
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-[#14171C]/15 bg-white px-4 py-6 text-sm text-[#14171C]/55">
                    No tenés avisos. Cuando te asignen un edificio u orden, o cambie algo de tus trabajos, aparece acá.
                </div>
            @endforelse
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    </div>
</x-app-layout>
