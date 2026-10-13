{{-- Foto de perfil del técnico (Etapa 8.2). --}}
<section class="space-y-3" data-profile-photo>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Foto de perfil</h2>
        <p class="mt-1 text-sm text-gray-600">La ven tu empresa y los clientes de los edificios que tenés asignados. JPG, PNG o WebP de hasta 2 MB.</p>
    </header>

    <div class="flex items-center gap-4">
        <x-user-photo :user="$user" :size="72" />
        <div class="flex flex-wrap gap-2">
            <form method="post" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data" x-data>
                @csrf
                <label class="t-btn-secondary t-btn-sm cursor-pointer">
                    {{ $user->avatar ? 'Cambiar foto' : 'Subir foto' }}
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" x-on:change="$el.form.submit()">
                </label>
            </form>
            @if($user->avatar)
                <form method="post" action="{{ route('profile.photo.destroy') }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="t-btn-secondary t-btn-sm">Quitar foto</button>
                </form>
            @endif
        </div>
    </div>
    <x-input-error :messages="$errors->get('photo')" />
    @if (in_array(session('status'), ['photo-updated', 'photo-removed'], true))
        <p class="text-sm text-gray-600">{{ session('status') === 'photo-updated' ? 'Foto actualizada.' : 'Foto quitada.' }}</p>
    @endif
</section>
