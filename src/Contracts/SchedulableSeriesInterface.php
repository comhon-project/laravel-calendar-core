<?php

namespace Comhon\Calendar\Contracts;

interface SchedulableSeriesInterface
{
    /**
     * @return string[]
     */
    public function series(): array;
}
