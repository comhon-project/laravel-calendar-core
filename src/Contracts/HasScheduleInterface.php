<?php

namespace Comhon\Calendar\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

interface HasScheduleInterface
{
    public function events(): BelongsToMany;

    /**
     * @return string[]
     */
    public function getIdentityProperties(): array;
}
