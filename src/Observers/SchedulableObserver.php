<?php

namespace Comhon\Calendar\Observers;

use Comhon\Calendar\Contracts\SchedulableInterface;
use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Comhon\Calendar\DTO\SchedulableSerie;
use Comhon\Calendar\Services\SchedulableSerieService;
use Comhon\Calendar\Services\SchedulableService;

class SchedulableObserver
{
    public function __construct(
        private SchedulableService $schedulableService,
        private SchedulableSerieService $schedulableSerieService
    ) {}

    public function deleting(SchedulableInterface|SchedulableSeriesInterface $schedulable)
    {
        if ($schedulable instanceof SchedulableInterface) {
            $this->schedulableService->cancelEvents($schedulable);
        } else {
            foreach ($schedulable->series() as $serie) {
                $this->schedulableSerieService->cancelEvents(new SchedulableSerie($schedulable, $serie));
            }
        }
    }
}
