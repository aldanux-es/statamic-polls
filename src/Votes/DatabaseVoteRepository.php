<?php

namespace Aldanux\StatamicPolls\Votes;

use Aldanux\StatamicPolls\Enums\RecordResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores votes in a table with a unique (poll_id, voter_id) index, so the database itself
 * guarantees one vote per voter even under concurrent requests.
 */
class DatabaseVoteRepository implements VoteRepository
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
    ) {}

    public function record(Vote $vote, int $maxPerIp = 0): RecordResult
    {
        try {
            return $this->connection->transaction(function () use ($vote, $maxPerIp): RecordResult {
                // Locks the (poll, ip) index range, so parallel votes from one address queue up.
                if ($maxPerIp > 0 && $this->query()
                    ->where('poll_id', $vote->pollId)
                    ->where('ip_hash', $vote->ipHash)
                    ->lockForUpdate()
                    ->count() >= $maxPerIp) {
                    return RecordResult::IpLimitReached;
                }

                $this->insert($vote);

                return RecordResult::Recorded;
            });
        } catch (UniqueConstraintViolationException) {
            return RecordResult::Duplicate;
        }
    }

    protected function insert(Vote $vote): void
    {
        $this->query()->insert([
            'poll_id' => $vote->pollId,
            'option_key' => $vote->optionKey,
            'voter_id' => $vote->voterId,
            'ip_hash' => $vote->ipHash,
            'site' => $vote->site,
            'created_at' => $vote->createdAt ?? CarbonImmutable::now(),
        ]);
    }

    public function hasVoted(string $pollId, string $voterId): bool
    {
        return $this->query()->where('poll_id', $pollId)->where('voter_id', $voterId)->exists();
    }

    public function countForIp(string $pollId, string $ipHash): int
    {
        return $this->query()->where('poll_id', $pollId)->where('ip_hash', $ipHash)->count();
    }

    public function tally(string $pollId): array
    {
        return $this->query()
            ->where('poll_id', $pollId)
            ->selectRaw('option_key, count(*) as votes')
            ->groupBy('option_key')
            ->pluck('votes', 'option_key')
            ->map(fn ($votes): int => (int) $votes)
            ->all();
    }

    public function all(string $pollId): iterable
    {
        return $this->query()
            ->where('poll_id', $pollId)
            ->orderBy('id')
            ->lazyById()
            ->map(fn (object $row): Vote => new Vote(
                $row->poll_id,
                $row->option_key,
                $row->voter_id,
                $row->ip_hash,
                $row->site,
                CarbonImmutable::parse($row->created_at),
            ));
    }

    public function forget(string $pollId, string $voterId): bool
    {
        return $this->query()->where('poll_id', $pollId)->where('voter_id', $voterId)->delete() > 0;
    }

    public function forgetIp(string $pollId, string $ipHash): int
    {
        return $this->query()->where('poll_id', $pollId)->where('ip_hash', $ipHash)->delete();
    }

    public function polls(): array
    {
        return $this->query()->distinct()->pluck('poll_id')->all();
    }

    protected function query(): Builder
    {
        return $this->connection->table($this->table);
    }
}
