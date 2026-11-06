<?php

namespace Aldanux\StatamicPolls;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The abuse checks of a vote: bots, honeypot, per-minute rate limit and optional Turnstile.
 * The per-IP cap needs the vote storage, so it lives in the PollManager.
 */
class VoteProtection
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|lighthouse|headless|curl|wget|python|httpclient|okhttp|go-http/i';

    public function isBot(Request $request): bool
    {
        if (! config('statamic-polls.protection.block_bots', true)) {
            return false;
        }

        $agent = (string) $request->userAgent();

        return $agent === '' || (bool) preg_match(self::BOTS, $agent);
    }

    public function honeypotTripped(Request $request): bool
    {
        $field = config('statamic-polls.protection.honeypot');

        return is_string($field) && $field !== '' && filled($request->input($field));
    }

    /**
     * Counts this attempt and tells whether the visitor went over the per-minute limit.
     */
    public function tooManyAttempts(Request $request): bool
    {
        $limit = (int) config('statamic-polls.protection.rate_limit_per_minute', 10);

        if ($limit <= 0) {
            return false;
        }

        $key = 'statamic-polls:'.$this->ipHash($request);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    public function turnstileEnabled(): bool
    {
        return filled(config('statamic-polls.turnstile.secret')) && filled(config('statamic-polls.turnstile.site_key'));
    }

    public function turnstilePasses(Request $request): bool
    {
        if (! $this->turnstileEnabled()) {
            return true;
        }

        $token = (string) $request->input('cf-turnstile-response', '');

        if ($token === '') {
            return false;
        }

        try {
            return (bool) Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('statamic-polls.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ])
                ->json('success', false);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The visitor's IP as a keyed hash: enough to cap votes per address without storing it.
     */
    public function ipHash(Request $request): string
    {
        return hash_hmac('sha256', 'ip:'.$request->ip(), (string) config('app.key'));
    }
}
