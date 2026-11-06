<?php

namespace Aldanux\StatamicPolls\Votes;

/**
 * Where votes live. Implementations must make `record` atomic: two concurrent votes from the
 * same voter must never both be stored.
 */
interface VoteRepository
{
    /**
     * Stores the vote unless this voter already voted in the poll.
     *
     * @return bool false when the voter had already voted
     */
    public function record(Vote $vote): bool;

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
