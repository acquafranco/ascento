<form method="POST" action="{{ route('logout') }}" class="mt-6">
    @csrf
    <button type="submit" class="text-sm text-ink/50 underline">Salir</button>
</form>
