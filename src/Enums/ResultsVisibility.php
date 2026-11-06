<?php

namespace Aldanux\StatamicPolls\Enums;

/**
 * When visitors may see the counts. AfterClose keeps them secret until the poll ends, so the
 * running tally can't sway the vote.
 */
enum ResultsVisibility: string
{
    case AfterClose = 'after_close';
    case AfterVote = 'after_vote';
    case Always = 'always';
}
