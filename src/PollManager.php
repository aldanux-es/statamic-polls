<?php

namespace Aldanux\StatamicPolls;

use Aldanux\StatamicPolls\Enums\Audience;
use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Enums\VoteOutcome;
use Aldanux\StatamicPolls\Voters\Voter;
use Aldanux\StatamicPolls\Votes\Vote;
use Aldanux\StatamicPolls\Votes\VoteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Entry point of the addon: finds polls, casts votes and opens/closes polls on schedule.
 */
class PollManager
{
    public function __construct(
        protected VoteRepository $votes,
        protected VoteProtection $protection,
    ) {}

    public function votes(): VoteRepository
    {
        return $this->votes;
    }

    public function collection(): string
    {
        return (string) config('statamic-polls.collection', 'polls');
    }

    /**
     * A poll by id (of the original or any translation), shown in the given site.
     */
    public function find(string $id, ?string $site = null): ?Poll
    {
        $entry = Entry::find($id);

        if (! $entry instanceof StatamicEntry || $entry->collectionHandle() !== $this->collection()) {
            return null;
        }

        if ($site) {
            $root = $entry->hasOrigin() ? $entry->root() : $entry;
            $localized = $root instanceof StatamicEntry ? $root->in($site) : null;
            $entry = $localized instanceof StatamicEntry ? $localized : $entry;
        }

        return Poll::fromEntry($entry);
    }

    /**
     * Published polls of a group that have already opened, newest first.
     *
     * @return Collection<int, Poll>
     */
    public function inGroup(?string $group, ?string $site = null): Collection
    {
        $site ??= Site::current()->handle();

        return Entry::query()
            ->where('collection', $this->collection())
            ->where('site', $site)
            ->whereStatus('published')
            ->get()
            ->map(fn (StatamicEntry $entry): Poll => Poll::fromEntry($entry))
            ->filter(fn (Poll $poll): bool => $group === null || $poll->group() === $group)
            ->reject(fn (Poll $poll): bool => $poll->status() === PollStatus::Scheduled)
            ->sortByDesc(fn (Poll $poll): int => $poll->opensAt()?->getTimestamp() ?? 0)
            ->values();
    }

    public function voter(Poll $poll): Voter
    {
        $class = config('statamic-polls.voters.'.$poll->audience()->value);

        return app(is_string($class) && $class !== '' ? $class : Voters\AnonymousVoter::class);
    }

    /**
     * The voter's keyed hash for this poll, or null when they can't be identified.
     */
    public function voterId(Request $request, Poll $poll, bool $create = false): ?string
    {
        $id = $this->voter($poll)->identify($request, $create);

        return $id === null ? null : hash_hmac('sha256', 'voter:'.$id, (string) config('app.key'));
    }

    public function hasVoted(Request $request, Poll $poll): bool
    {
        $voterId = $this->voterId($request, $poll);

        return $voterId !== null && $this->votes->hasVoted($poll->id(), $voterId);
    }

    public function vote(Request $request, Poll $poll, string $optionKey): VoteOutcome
    {
        if ($this->protection->isBot($request) || $this->protection->honeypotTripped($request)) {
            return VoteOutcome::Ignored;
        }

        if (! $poll->isPublished() || $poll->status() !== PollStatus::Open) {
            return VoteOutcome::NotOpen;
        }

        if ($this->protection->tooManyAttempts($request)) {
            return VoteOutcome::TooManyAttempts;
        }

        if (! $poll->option($optionKey)) {
            return VoteOutcome::InvalidOption;
        }

        if ($poll->audience() === Audience::Authenticated && $this->voterId($request, $poll) === null) {
            return VoteOutcome::LoginRequired;
        }

        if (! $this->protection->turnstilePasses($request)) {
            return VoteOutcome::VerificationFailed;
        }

        $ipHash = $this->protection->ipHash($request);
        $maxPerIp = (int) config('statamic-polls.protection.max_votes_per_ip', 25);

        if ($maxPerIp > 0 && $this->votes->countForIp($poll->id(), $ipHash) >= $maxPerIp) {
            return VoteOutcome::TooManyAttempts;
        }

        $voterId = $this->voterId($request, $poll, create: true);

        if ($voterId === null) {
            return VoteOutcome::LoginRequired;
        }

        $recorded = $this->votes->record(new Vote(
            pollId: $poll->id(),
            optionKey: $optionKey,
            voterId: $voterId,
            ipHash: $ipHash,
            site: $poll->site(),
            createdAt: CarbonImmutable::now(),
        ));

        return $recorded ? VoteOutcome::Recorded : VoteOutcome::AlreadyVoted;
    }

    /**
     * The final count once frozen; otherwise the live count from storage.
     */
    public function results(Poll $poll): PollResults
    {
        $frozen = $poll->frozenResults();

        return new PollResults($poll, $frozen['tally'] ?? $this->votes->tally($poll->id()));
    }

    /**
     * Snapshots the options of polls that just opened and freezes the results of polls that
     * just closed. Both save the entry, which refreshes static caching. Run every minute.
     *
     * @return array{opened: array<int, string>, closed: array<int, string>}
     */
    public function sync(): array
    {
        $opened = [];
        $closed = [];

        Entry::query()
            ->where('collection', $this->collection())
            ->whereStatus('published')
            ->get()
            ->reject(fn (StatamicEntry $entry): bool => $entry->hasOrigin())
            ->each(function (StatamicEntry $entry) use (&$opened, &$closed): void {
                $poll = Poll::fromEntry($entry);
                $status = $poll->status();

                if ($status !== PollStatus::Scheduled && ! $poll->hasSnapshot()) {
                    $poll->takeSnapshot();
                    $opened[] = $poll->id();
                }

                if ($status === PollStatus::Closed && $poll->frozenResults() === null) {
                    $poll->freezeResults($this->votes->tally($poll->id()));
                    $closed[] = $poll->id();
                }
            });

        return ['opened' => $opened, 'closed' => $closed];
    }
}
