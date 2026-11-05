<?php

namespace Comhon\Calendar\Traits;

trait HasIdentityTrait
{
    public function getIdentity(): array
    {
        return $this->only($this->getIdentityColumns());
    }
}
