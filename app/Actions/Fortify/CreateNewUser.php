<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        abort(403, 'Membership is by invitation only.');
    }
}
