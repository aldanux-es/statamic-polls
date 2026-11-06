<?php

use Aldanux\StatamicPolls\Voters\AnonymousVoter;
use Aldanux\StatamicPolls\Voters\AuthenticatedVoter;

return [

    /*
    |--------------------------------------------------------------------------
    | Polls collection
    |--------------------------------------------------------------------------
    |
    | Polls are regular entries of this collection, so editors manage them in
    | the control panel like any other content. `php please polls:install`
    | creates the collection and its blueprint.
    |
    */

    'collection' => 'polls',

    /*
    |--------------------------------------------------------------------------
    | Vote storage
    |--------------------------------------------------------------------------
    |
    | Votes never live in entries: they are written by visitors, often at the
    | same time, and must stay out of git. Pick the driver that fits the site:
    |
    | - "file": append-only files in storage/, works on any install (flat-file
    |   sites included). Fine for thousands of votes per poll.
    | - "database": a `poll_votes` table, recommended for busy sites. Publish
    |   and run the migration first.
    |
    */

    'driver' => env('POLLS_DRIVER', 'file'),

    'drivers' => [

        'file' => [
            'path' => storage_path('statamic-polls'),
        ],

        'database' => [
            'connection' => env('POLLS_DB_CONNECTION'),
            'table' => 'poll_votes',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Voters
    |--------------------------------------------------------------------------
    |
    | Each poll picks an audience. "public" polls identify visitors with a
    | signed cookie; "authenticated" polls require a logged-in user of the
    | given guard (null = the app's default guard). Register your own voter by
    | implementing Aldanux\StatamicPolls\Voters\Voter.
    |
    */

    'voters' => [
        'public' => AnonymousVoter::class,
        'authenticated' => AuthenticatedVoter::class,
    ],

    'guard' => env('POLLS_GUARD'),

    'cookie' => [
        'name' => 'poll_voter',
        'minutes' => 60 * 24 * 365 * 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Abuse protection
    |--------------------------------------------------------------------------
    |
    | Public polls are anonymous, so these limits keep them honest enough:
    | a hidden honeypot field, a per-minute rate limit and a cap of votes per
    | IP address and poll (keep it generous: offices, stadiums and mobile
    | carriers share addresses). Bots are answered as if the vote counted.
    |
    */

    'protection' => [
        'honeypot' => 'poll_website',
        'block_bots' => true,
        'rate_limit_per_minute' => 10,
        'max_votes_per_ip' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile (optional)
    |--------------------------------------------------------------------------
    |
    | When both keys are set, every vote must carry a valid Turnstile token.
    |
    */

    'turnstile' => [
        'site_key' => env('POLLS_TURNSTILE_SITE_KEY'),
        'secret' => env('POLLS_TURNSTILE_SECRET'),
    ],

];
