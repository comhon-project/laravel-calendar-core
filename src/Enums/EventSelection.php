<?php

namespace Comhon\Calendar\Enums;

enum EventSelection
{
    /** end_at > from (ongoing events included) */
    case Overlapping;

    /** start_at >= from */
    case StartingFrom;

    /** start_at > from */
    case StartingAfter;
}
