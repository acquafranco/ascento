<?php

namespace App\Policies;

use App\Models\User;

class UserTemplatePolicy
{
    public function viewTemplate(User $authUser, User $user): bool
    {
        // admin ve todo
        if ($authUser->role === 'admin') {
            return true;
        }

        // usuario solo su template
        return $authUser->id === $user->id;
    }
}
