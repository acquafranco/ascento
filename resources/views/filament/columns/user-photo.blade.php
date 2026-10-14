<div style="padding:0 .75rem" data-technician-photo>
    <x-user-photo :user="$getRecord()" :size="32" />
    {{-- La celda es un enlace a la ficha: sin esto quedaba sin nombre para lectores de pantalla. --}}
    <span class="fi-sr-only">{{ $getRecord()->name }}</span>
</div>
