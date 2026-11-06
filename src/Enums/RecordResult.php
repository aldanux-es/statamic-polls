<?php

namespace Aldanux\StatamicPolls\Enums;

/**
 * What the vote storage did with a vote. The checks run in the same atomic step as the
 * write, so a burst of parallel requests can't slip past them.
 */
enum RecordResult: string
{
    case Recorded = 'recorded';
    case Duplicate = 'duplicate';
    case IpLimitReached = 'ip_limit_reached';
}
