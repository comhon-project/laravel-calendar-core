<?php

namespace Comhon\Calendar\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

interface HasScheduleInterface extends HasIdentityInterface
{
    public function events(): BelongsToMany;
}
