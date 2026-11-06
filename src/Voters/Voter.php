<?php

namespace Aldanux\StatamicPolls\Voters;

use Illuminate\Http\Request;

/**
 * Identifies who is voting. The id is hashed by the repository caller, never stored raw.
 */
interface Voter
{
    /**
     * A stable id for the current visitor, or null when they can't vote (e.g. not logged in).
     * With $create, a voter that needs it may start remembering the visitor (set a cookie);
     * without it, identifying must have no side effects.
     */
    public function identify(Request $request, bool $create = false): ?string;
}
