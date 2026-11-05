<?php

namespace Comhon\Calendar\Contracts;

interface HasIdentityInterface
{
    /**
     * columns to load to build identity
     *
     * @return string[]
     */
    public function getIdentityColumns(): array;

    /**
     * exported identity
     */
    public function getIdentity(): array;
}
