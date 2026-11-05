<?php

namespace App\Models;

class UserWithoutKeyIdentity extends User
{
    protected $table = 'users';

    public function getIdentityColumns(): array
    {
        return ['name', 'created_at'];
    }
}
