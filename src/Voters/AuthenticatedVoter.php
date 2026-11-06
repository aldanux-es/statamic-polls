<?php

namespace Aldanux\StatamicPolls\Voters;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Any user logged in through a Laravel guard: Statamic users, an app's own accounts, or a
 * customer area. Set the guard in `statamic-polls.guard` (null = the default guard).
 */
class AuthenticatedVoter implements Voter
{
    public function identify(Request $request, bool $create = false): ?string
    {
        $guard = config('statamic-polls.guard');
        $id = Auth::guard($guard)->id();

        if ($id === null) {
            return null;
        }

        return 'user:'.($guard ?? config('auth.defaults.guard')).':'.$id;
    }
}
