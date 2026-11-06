<?php

namespace Aldanux\StatamicPolls\Enums;

enum PollStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
}
