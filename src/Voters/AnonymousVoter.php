<?php

namespace Aldanux\StatamicPolls\Voters;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Remembers anonymous visitors with a random id in an (encrypted) cookie. The cookie is only
 * set when the visitor actually votes, never just for viewing a poll.
 */
class AnonymousVoter implements Voter
{
    public function identify(Request $request, bool $create = false): ?string
    {
        $name = (string) config('statamic-polls.cookie.name', 'poll_voter');
        $id = $request->cookie($name);

        if (is_string($id) && Str::isUuid($id)) {
            return 'anonymous:'.$id;
        }

        if (! $create) {
            return null;
        }

        $id = (string) Str::uuid();
        Cookie::queue($name, $id, (int) config('statamic-polls.cookie.minutes', 60 * 24 * 365 * 2));
        $request->cookies->set($name, $id);

        return 'anonymous:'.$id;
    }
}
