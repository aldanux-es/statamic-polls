<?php

namespace Aldanux\StatamicPolls;

use Aldanux\StatamicPolls\Options\PollOption;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Counts per answer, in the poll's own order, with percentages and the winner(s) — ties
 * share the win.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PollResults implements Arrayable
{
    /**
     * @param  array<string, int>  $tally
     */
    public function __construct(
        public Poll $poll,
        public array $tally,
    ) {}

    public function total(): int
    {
        return array_sum($this->tally);
    }

    public function votesFor(string $key): int
    {
        return $this->tally[$key] ?? 0;
    }

    /**
     * @return array<int, string>
     */
    public function winners(): array
    {
        $max = $this->tally === [] ? 0 : max($this->tally);

        if ($max === 0) {
            return [];
        }

        return $this->poll->options()
            ->filter(fn (PollOption $option): bool => $this->votesFor($option->key) === $max)
            ->map(fn (PollOption $option): string => $option->key)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string, votes: int, percent: float, is_winner: bool}>
     */
    public function options(): array
    {
        $total = $this->total();
        $winners = $this->winners();

        return $this->poll->options()
            ->map(fn (PollOption $option): array => [
                'key' => $option->key,
                'label' => $option->toTemplateData($this->poll->site())['label'],
                'votes' => $this->votesFor($option->key),
                'percent' => $total > 0 ? round($this->votesFor($option->key) * 100 / $total, 1) : 0.0,
                'is_winner' => in_array($option->key, $winners, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{total: int, winners: array<int, string>, options: array<int, array{key: string, label: string, votes: int, percent: float, is_winner: bool}>}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'winners' => $this->winners(),
            'options' => $this->options(),
        ];
    }
}
