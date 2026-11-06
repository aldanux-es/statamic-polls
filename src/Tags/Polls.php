<?php

namespace Aldanux\StatamicPolls\Tags;

use Aldanux\StatamicPolls\Enums\Audience;
use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Options\PollOption;
use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Aldanux\StatamicPolls\VoteProtection;
use Carbon\CarbonImmutable;
use Statamic\Contracts\Query\Builder;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Site;
use Statamic\Tags\Tags;

/**
 * {{ polls :poll="poll" }}                  renders a poll (or, as a pair, exposes its data)
 * {{ polls:archive group="player-of-month" }} every opened poll of a group, newest first
 * {{ polls:winner group="player-of-month" }}  the winning answer(s) of the latest closed poll
 *
 * The markup is cache-safe: nothing in it depends on the visitor. Their state (voted, login)
 * is fetched by polls.js from the state endpoint.
 */
class Polls extends Tags
{
    protected static $handle = 'polls';

    public function index(): string|array
    {
        $poll = $this->pollFromParams();

        if (! $poll || ! $poll->isPublished()) {
            return $this->isPair ? [] : '';
        }

        $data = $this->pollData($poll);

        return $this->isPair ? $data : view('statamic-polls::poll', $data)->render();
    }

    public function archive(): string|array
    {
        $polls = $this->manager()
            ->inGroup($this->params->get('group'), $this->site())
            ->map(fn (Poll $poll): array => $this->pollData($poll))
            ->values()
            ->all();

        $data = ['items' => $polls, 'total_items' => count($polls), 'no_results' => $polls === []];

        return $this->isPair ? $data : view('statamic-polls::archive', $data)->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function winner(): array
    {
        $poll = $this->manager()
            ->inGroup($this->params->get('group'), $this->site())
            ->first(fn (Poll $poll): bool => $poll->status() === PollStatus::Closed && $poll->frozenResults() !== null);

        if (! $poll) {
            return [];
        }

        $data = $this->pollData($poll);
        $winners = array_values(array_filter($data['results'] ?? [], fn (array $option): bool => $option['is_winner']));

        return ['poll' => $data, 'winners' => $winners, 'winner' => $winners[0] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pollData(Poll $poll): array
    {
        $manager = $this->manager();
        $status = $poll->status();
        $site = $poll->site();
        $options = $poll->options();
        $results = null;

        // Only final results go in the markup: live counts would be frozen in the static cache.
        if ($status === PollStatus::Closed) {
            $tally = $manager->results($poll);
            $optionsByKey = $options->keyBy(fn (PollOption $option): string => $option->key);

            $results = collect($tally->options())
                ->map(fn (array $result): array => [
                    ...$optionsByKey[$result['key']]->toTemplateData($site),
                    ...$result,
                    'votes_text' => trans_choice('statamic-polls::messages.votes', $result['votes'], [], $this->locale($site)),
                ])
                ->all();
        }

        $opensAt = $this->displayDate($poll->opensAt());
        $closesAt = $this->displayDate($poll->closesAt());

        return [
            'id' => $poll->id(),
            'title' => $poll->title(),
            'question' => $poll->question(),
            'description' => $poll->entry()->value('description'),
            'group' => $poll->group(),
            'site' => $site,
            'status' => $status->value,
            'is_scheduled' => $status === PollStatus::Scheduled,
            'is_open' => $status === PollStatus::Open,
            'is_closed' => $status === PollStatus::Closed,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'opens_on_text' => $opensAt ? $this->sentence('opens_on', $opensAt, $site) : null,
            'closes_on_text' => $closesAt ? $this->sentence('closes_on', $closesAt, $site) : null,
            'login_required' => $poll->audience() === Audience::Authenticated,
            'results_visibility' => $poll->resultsVisibility()->value,
            'options' => $options->map(fn (PollOption $option): array => $option->toTemplateData($site))->all(),
            'results' => $results,
            'total_votes' => $results === null ? null : array_sum(array_column($results, 'votes')),
            'total_votes_text' => $results === null ? null : trans_choice('statamic-polls::messages.votes', array_sum(array_column($results, 'votes')), [], $this->locale($site)),
            'state_url' => route('statamic.statamic-polls.state', ['poll' => $poll->id(), 'site' => $site], false),
            'vote_url' => route('statamic.statamic-polls.vote', ['poll' => $poll->id()], false),
            'honeypot' => config('statamic-polls.protection.honeypot'),
            'turnstile_site_key' => app(VoteProtection::class)->turnstileEnabled() ? config('statamic-polls.turnstile.site_key') : null,
            'entry' => $poll->entry(),
        ];
    }

    protected function pollFromParams(): ?Poll
    {
        $value = $this->params->get('poll') ?? $this->params->get('id') ?? $this->params->get('from');

        // An entries field gives a query builder (or a collection) even with max_items: 1.
        if ($value instanceof Builder && method_exists($value, 'first')) {
            $value = $value->first();
        }

        if (is_iterable($value)) {
            $value = collect($value)->first();
        }

        if ($value instanceof StatamicEntry) {
            return $this->manager()->find((string) $value->id(), $this->site());
        }

        return is_string($value) && $value !== '' ? $this->manager()->find($value, $this->site()) : null;
    }

    protected function displayDate(?CarbonImmutable $date): ?CarbonImmutable
    {
        $timezone = config('statamic.system.display_timezone') ?: config('app.timezone');

        return $date?->setTimezone($timezone);
    }

    protected function sentence(string $key, CarbonImmutable $date, string $site): string
    {
        $locale = $this->locale($site);

        return __('statamic-polls::messages.'.$key, ['date' => $date->locale($locale)->isoFormat('LLL')], $locale);
    }

    protected function locale(string $site): string
    {
        return Site::get($site)?->lang() ?? app()->getLocale();
    }

    protected function site(): string
    {
        $site = $this->params->get('site') ?? $this->context->get('site');

        if ($site instanceof \Statamic\Sites\Site) {
            return $site->handle();
        }

        return is_string($site) && Site::get($site) ? $site : Site::current()->handle();
    }

    protected function manager(): PollManager
    {
        return app(PollManager::class);
    }
}
