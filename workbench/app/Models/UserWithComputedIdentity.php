<?php

namespace App\Models;

class UserWithComputedIdentity extends User
{
    protected $table = 'users';

    public function getIdentityColumns(): array
    {
        return ['name', 'first_name'];
    }

    public function getIdentity(): array
    {
        return ['full_name' => "{$this->first_name} {$this->name}"];
    }
}
