<?php

namespace Aldanux\StatamicPolls\Listeners;

use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Options\OptionResolver;
use Aldanux\StatamicPolls\Options\PollOption;
use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Toast;

/**
 * An open poll's options are frozen (see Poll::takeSnapshot), so editing them in the control
 * panel would otherwise do nothing on the site, silently. When an editor changes the options
 * of an open poll that has no votes yet (a forgotten nominee, a wrong pick), the snapshot is
 * rebuilt from the new options in the same save. Once votes exist the snapshot stays — votes
 * point at its keys — and the editor is told why the change doesn't apply.
 */
class RefreshOptionsSnapshot
{
    public function __construct(
        protected PollManager $polls,
        protected OptionResolver $resolver,
    ) {}

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry instanceof StatamicEntry
            || $entry->collectionHandle() !== $this->polls->collection()
            || $entry->hasOrigin()) {
            return;
        }

        $poll = Poll::fromEntry($entry);

        if (! $poll->hasSnapshot() || $poll->status() !== PollStatus::Open) {
            return;
        }

        $options = $this->resolver->resolve($entry);
        $keys = fn (iterable $options): array => collect($options)->map(fn (PollOption $option): string => $option->key)->all();

        if ($keys($options) === $keys($poll->options())) {
            return;
        }

        if (array_sum($this->polls->votes()->tally($poll->id())) > 0) {
            Toast::info(__('statamic-polls::cp.options_locked'));

            return;
        }

        $entry->set(Poll::SNAPSHOT_KEY, $options->map(fn (PollOption $option): array => $option->toArray())->all());
    }
}
