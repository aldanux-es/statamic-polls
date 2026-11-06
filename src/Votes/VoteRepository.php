<?php

namespace Aldanux\StatamicPolls\Votes;

use Aldanux\StatamicPolls\Enums\RecordResult;

/**
 * Where votes live. Implementations must make `record` atomic: two concurrent votes from the
 * same voter must never both be stored.
 */
interface VoteRepository
{
    /**
     * Stores the vote unless this voter already voted in the poll or, when $maxPerIp is
     * above zero, the vote's IP already cast that many votes. Checks and write are atomic.
     */
    public function record(Vote $vote, int $maxPerIp = 0): RecordResult;

    public function hasVoted(string $pollId, string $voterId): bool;

    public function countForIp(string $pollId, string $ipHash): int;

    /**
     * @return array<string, int> votes per option key
     */
    public function tally(string $pollId): array;

    /**
     * @return iterable<int, Vote>
     */
    public function all(string $pollId): iterable;

    /**
     * Removes a voter's vote (e.g. one flagged as fraud).
     */
    public function forget(string $pollId, string $voterId): bool;

    /**
     * @return array<int, string> ids of every poll with stored votes
     */
    public function polls(): array;
}
