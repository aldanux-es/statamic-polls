<?php

namespace Aldanux\StatamicPolls\Commands;

use Aldanux\StatamicPolls\ServiceProvider;
use Illuminate\Console\Command;

class MigrateVotes extends Command
{
    protected $signature = 'polls:migrate-votes {from : file or database} {to : file or database}';

    protected $description = 'Copy every vote from one storage driver to another';

    public function handle(): int
    {
        /** @var ServiceProvider $provider */
        $provider = $this->laravel->getProvider(ServiceProvider::class);
        $from = $provider->repository((string) $this->argument('from'));
        $to = $provider->repository((string) $this->argument('to'));

        $copied = 0;
        $skipped = 0;

        foreach ($from->polls() as $pollId) {
            foreach ($from->all($pollId) as $vote) {
                $to->record($vote) ? $copied++ : $skipped++;
            }
        }

        $this->components->info("{$copied} votes copied, {$skipped} already present.");

        return self::SUCCESS;
    }
}
