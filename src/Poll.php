<?php

namespace Aldanux\StatamicPolls;

use Aldanux\StatamicPolls\Enums\Audience;
use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Enums\ResultsVisibility;
use Aldanux\StatamicPolls\Options\OptionResolver;
use Aldanux\StatamicPolls\Options\PollOption;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;

/**
 * A poll entry. Wraps the entry being displayed (which may be a translation) while votes,
 * the options snapshot and the frozen results always belong to the original entry, so every
 * language counts towards the same poll.
 */
class Poll
{
    public const SNAPSHOT_KEY = 'poll_options_snapshot';

    public const RESULTS_KEY = 'poll_results';

    protected ?Collection $resolvedOptions = null;

    public function __construct(protected StatamicEntry $entry) {}

    public static function fromEntry(StatamicEntry $entry): self
    {
        return new self($entry);
    }

    public function id(): string
    {
        return (string) $this->root()->id();
    }

    public function entry(): StatamicEntry
    {
        return $this->entry;
    }

    public function root(): StatamicEntry
    {
        return $this->entry->hasOrigin() ? $this->entry->root() : $this->entry;
    }

    public function site(): string
    {
        return $this->entry->locale();
    }

    public function title(): string
    {
        return (string) $this->entry->value('title');
    }

    public function question(): string
    {
        $question = trim((string) $this->entry->value('question'));

        return $question !== '' ? $question : $this->title();
    }

    public function group(): ?string
    {
        $group = trim((string) $this->root()->value('group'));

        return $group !== '' ? $group : null;
    }

    public function opensAt(): ?CarbonImmutable
    {
        return $this->date('opens_at');
    }

    public function closesAt(): ?CarbonImmutable
    {
        return $this->date('closes_at');
    }

    public function status(?CarbonInterface $now = null): PollStatus
    {
        $now ??= CarbonImmutable::now();

        if ($this->opensAt()?->isAfter($now)) {
            return PollStatus::Scheduled;
        }

        if ($this->closesAt() && ! $this->closesAt()->isAfter($now)) {
            return PollStatus::Closed;
        }

        return PollStatus::Open;
    }

    public function isPublished(): bool
    {
        return $this->entry->status() === 'published' && $this->root()->published();
    }

    public function audience(): Audience
    {
        return Audience::tryFrom((string) $this->root()->value('audience')) ?? Audience::Public;
    }

    public function resultsVisibility(): ResultsVisibility
    {
        return ResultsVisibility::tryFrom((string) $this->root()->value('results_visibility')) ?? ResultsVisibility::AfterClose;
    }

    /**
     * Whether a visitor may see the counts right now.
     */
    public function resultsVisibleTo(bool $hasVoted): bool
    {
        if ($this->status() === PollStatus::Closed) {
            return true;
        }

        return match ($this->resultsVisibility()) {
            ResultsVisibility::Always => true,
            ResultsVisibility::AfterVote => $hasVoted,
            ResultsVisibility::AfterClose => false,
        };
    }

    /**
     * The answers: the snapshot taken when the poll opened, or the live configuration before.
     *
     * @return Collection<int, PollOption>
     */
    public function options(): Collection
    {
        if ($this->resolvedOptions) {
            return $this->resolvedOptions;
        }

        $snapshot = $this->root()->get(self::SNAPSHOT_KEY);

        return $this->resolvedOptions = is_array($snapshot)
            ? collect($snapshot)->map(fn (array $option): PollOption => PollOption::fromArray($option))->values()
            : app(OptionResolver::class)->resolve($this->root());
    }

    public function option(string $key): ?PollOption
    {
        return $this->options()->first(fn (PollOption $option): bool => $option->key === $key);
    }

    public function hasSnapshot(): bool
    {
        return is_array($this->root()->get(self::SNAPSHOT_KEY));
    }

    /**
     * Freezes the answers, so a player leaving mid-month doesn't break the poll or its votes.
     */
    public function takeSnapshot(): void
    {
        $options = app(OptionResolver::class)->resolve($this->root());

        $this->root()
            ->set(self::SNAPSHOT_KEY, $options->map(fn (PollOption $option): array => $option->toArray())->all())
            ->save();

        $this->resolvedOptions = $options;
    }

    /**
     * @return array{tally: array<string, int>, total: int, closed_at: string}|null
     */
    public function frozenResults(): ?array
    {
        $results = $this->root()->get(self::RESULTS_KEY);

        return is_array($results) ? $results : null;
    }

    /**
     * Stores the final count on the entry. Saving it also lets static caching refresh the
     * pages that show the poll.
     *
     * @param  array<string, int>  $tally
     */
    public function freezeResults(array $tally): void
    {
        $this->root()->set(self::RESULTS_KEY, [
            'tally' => $tally,
            'total' => array_sum($tally),
            'closed_at' => CarbonImmutable::now()->toIso8601String(),
        ])->save();
    }

    protected function date(string $field): ?CarbonImmutable
    {
        $value = $this->root()->augmentedValue($field)->value();

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return filled($value) ? CarbonImmutable::parse((string) $value) : null;
    }
}
