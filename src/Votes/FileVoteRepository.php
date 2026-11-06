<?php

namespace Aldanux\StatamicPolls\Votes;

use Aldanux\StatamicPolls\Enums\RecordResult;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores votes as JSON lines, one file per poll, under storage/ (outside content/ and git).
 * Writes take an exclusive lock, so the duplicate check and the append happen atomically even
 * with concurrent requests. Reads take a shared lock.
 */
class FileVoteRepository implements VoteRepository
{
    public function __construct(
        protected Filesystem $files,
        protected string $path,
    ) {}

    public function record(Vote $vote, int $maxPerIp = 0): RecordResult
    {
        return $this->withLock($vote->pollId, LOCK_EX, function ($handle) use ($vote, $maxPerIp): RecordResult {
            $votesFromIp = 0;

            foreach ($this->lines($handle) as $line) {
                if (($line['voter'] ?? null) === $vote->voterId) {
                    return RecordResult::Duplicate;
                }

                if (($line['ip'] ?? null) === $vote->ipHash) {
                    $votesFromIp++;
                }
            }

            if ($maxPerIp > 0 && $votesFromIp >= $maxPerIp) {
                return RecordResult::IpLimitReached;
            }

            $this->append($handle, json_encode($vote->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return RecordResult::Recorded;
        });
    }

    public function hasVoted(string $pollId, string $voterId): bool
    {
        return $this->read($pollId, function ($handle) use ($voterId): bool {
            foreach ($this->lines($handle) as $line) {
                if (($line['voter'] ?? null) === $voterId) {
                    return true;
                }
            }

            return false;
        }, false);
    }

    public function countForIp(string $pollId, string $ipHash): int
    {
        return $this->read($pollId, function ($handle) use ($ipHash): int {
            $count = 0;

            foreach ($this->lines($handle) as $line) {
                if (($line['ip'] ?? null) === $ipHash) {
                    $count++;
                }
            }

            return $count;
        }, 0);
    }

    public function tally(string $pollId): array
    {
        return $this->read($pollId, function ($handle): array {
            $tally = [];

            foreach ($this->lines($handle) as $line) {
                $tally[$line['option']] = ($tally[$line['option']] ?? 0) + 1;
            }

            return $tally;
        }, []);
    }

    public function all(string $pollId): iterable
    {
        return $this->read($pollId, function ($handle): array {
            $votes = [];

            foreach ($this->lines($handle) as $line) {
                $votes[] = Vote::fromArray($line);
            }

            return $votes;
        }, []);
    }

    public function forget(string $pollId, string $voterId): bool
    {
        if (! $this->files->exists($this->file($pollId))) {
            return false;
        }

        return $this->withLock($pollId, LOCK_EX, function ($handle) use ($voterId): bool {
            $kept = [];
            $removed = false;

            foreach ($this->lines($handle) as $line) {
                if (($line['voter'] ?? null) === $voterId) {
                    $removed = true;

                    continue;
                }

                $kept[] = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            if ($removed) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $kept === [] ? '' : implode("\n", $kept)."\n");
                fflush($handle);
            }

            return $removed;
        });
    }

    public function polls(): array
    {
        if (! $this->files->isDirectory($this->path)) {
            return [];
        }

        return collect($this->files->files($this->path))
            ->filter(fn ($file): bool => $file->getExtension() === 'jsonl')
            ->map(fn ($file): string => $file->getBasename('.jsonl'))
            ->values()
            ->all();
    }

    /**
     * Appends a line, first closing any line a crashed write left without its newline, so
     * the next vote isn't glued to (and lost with) the torn one.
     *
     * @param  resource  $handle
     */
    protected function append($handle, string $line): void
    {
        $stat = fstat($handle);

        if ($stat !== false && $stat['size'] > 0) {
            fseek($handle, -1, SEEK_END);

            if (fread($handle, 1) !== "\n") {
                $line = "\n".$line;
            }
        }

        fseek($handle, 0, SEEK_END);
        fwrite($handle, $line."\n");
        fflush($handle);
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $callback
     * @param  T  $default
     * @return T
     */
    protected function read(string $pollId, callable $callback, mixed $default): mixed
    {
        if (! $this->files->exists($this->file($pollId))) {
            return $default;
        }

        return $this->withLock($pollId, LOCK_SH, $callback);
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $callback
     * @return T
     */
    protected function withLock(string $pollId, int $lock, callable $callback): mixed
    {
        $this->files->ensureDirectoryExists($this->path);

        $handle = fopen($this->file($pollId), $lock === LOCK_EX ? 'c+' : 'r');

        if ($handle === false || ! flock($handle, $lock)) {
            throw new RuntimeException("Unable to lock the votes file of poll [{$pollId}].");
        }

        try {
            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return \Generator<int, array<string, mixed>> decoded lines that carry at least an option and a voter
     */
    protected function lines($handle): \Generator
    {
        rewind($handle);

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['option'], $decoded['voter'])) {
                yield $decoded;
            }
        }
    }

    protected function file(string $pollId): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $pollId)) {
            throw new InvalidArgumentException("Invalid poll id [{$pollId}].");
        }

        return rtrim($this->path, '/')."/{$pollId}.jsonl";
    }
}
