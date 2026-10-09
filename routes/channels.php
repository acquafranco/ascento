<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Canal privado de avisos de cada usuario. Solo el propio usuario (no otro de
| su empresa, ni de otra, ni el SuperAdmin) puede suscribirse. Un usuario
| desactivado no se autentica, así que tampoco.
*/
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id && ! $user->trashed();
});
