<?php

namespace Comhon\Calendar\Http\Controllers;

use Illuminate\Support\Facades\Gate;

class Controller
{
    protected function authorize(string $ability, ...$arguments)
    {
        return config('calendar-core.use_policies')
            ? Gate::authorize($ability, ...$arguments)
            : true;
    }
}
