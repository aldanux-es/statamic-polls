<?php

namespace Aldanux\StatamicPolls\Commands;

use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;

/**
 * Clones a poll (and its translations) for a new round, e.g. the next month of a monthly
 * vote. The options configuration is copied, but not the snapshot, results or votes: the
 * new poll resolves its options again when it opens (current squad, not last month's).
 */
class DuplicatePoll extends Command
{
    protected $signature = 'polls:duplicate
        {poll : Id of the poll to copy}
        {--next-month : Open on the 1st of the month after the source opened and close at the end of it}
        {--opens= : Opening date and time (display timezone)}
        {--closes= : Closing date and time (display timezone)}
        {--title= : Title of the copy}
        {--publish : Publish the copy right away}';

    protected $description = 'Copy a poll for a new round';

    public function handle(PollManager $polls): int
    {
        $source = $polls->find((string) $this->argument('poll'));

        if (! $source) {
            $this->components->error('Poll not found.');

            return self::FAILURE;
        }

        [$opensAt, $closesAt] = $this->dates($source);

        if (! $opensAt || ! $closesAt || ! $closesAt->isAfter($opensAt)) {
            $this->components->error('Give --next-month or valid --opens and --closes dates.');

            return self::FAILURE;
        }

        $title = (string) ($this->option('title') ?: $source->title().' '.$opensAt->locale(app()->getLocale())->isoFormat('MMMM YYYY'));
        $copy = $this->copy($source->root(), $title, $opensAt, $closesAt);

        Entry::query()
            ->where('collection', $polls->collection())
            ->where('origin', $source->id())
            ->get()
            ->each(fn (StatamicEntry $translation) => $this->copy($translation, $title, $opensAt, $closesAt, $copy));

        $this->components->info("Poll [{$copy->id()}] \"{$title}\" created.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    protected function dates(Poll $source): array
    {
        $timezone = config('statamic.system.display_timezone') ?: config('app.timezone');

        if ($this->option('next-month')) {
            $base = ($source->opensAt() ?? CarbonImmutable::now())->setTimezone($timezone);
            $opens = $base->addMonthNoOverflow()->startOfMonth();

            return [$opens, $opens->endOfMonth()->startOfMinute()];
        }

        $parse = fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value, $timezone) : null;

        return [$parse($this->option('opens')), $parse($this->option('closes'))];
    }

    protected function copy(StatamicEntry $entry, string $title, CarbonImmutable $opensAt, CarbonImmutable $closesAt, ?StatamicEntry $origin = null): StatamicEntry
    {
        $data = $entry->data()->except([Poll::SNAPSHOT_KEY, Poll::RESULTS_KEY, 'updated_at', 'updated_by'])->all();
        $storageTimezone = config('app.timezone');

        if (! $origin) {
            $data['opens_at'] = $opensAt->setTimezone($storageTimezone)->format('Y-m-d H:i');
            $data['closes_at'] = $closesAt->setTimezone($storageTimezone)->format('Y-m-d H:i');
        }

        $data['title'] = $title;

        // A propagating collection may have created the translation when the original was saved.
        $existing = $origin?->fresh()?->in($entry->locale());
        $copy = $existing instanceof StatamicEntry && $existing->id() !== $origin?->id() ? $existing : $this->newEntry($entry);

        $copy->slug(Str::slug($title))
            ->published((bool) $this->option('publish'))
            ->data($data);

        if ($origin) {
            $copy->origin($origin);
        }

        $copy->save();

        return $copy;
    }

    protected function newEntry(StatamicEntry $source): StatamicEntry
    {
        $entry = Entry::make();

        if (! $entry instanceof StatamicEntry) {
            throw new \LogicException('Unsupported entry implementation.');
        }

        return $entry->id((string) Str::uuid())
            ->collection($source->collectionHandle())
            ->locale($source->locale());
    }
}
