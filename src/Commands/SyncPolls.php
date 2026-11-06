<?php

namespace Aldanux\StatamicPolls\Commands;

use Aldanux\StatamicPolls\PollManager;
use Illuminate\Console\Command;

class SyncPolls extends Command
{
    protected $signature = 'polls:sync';

    protected $description = 'Freeze the options of polls that opened and the results of polls that closed';

    public function handle(PollManager $polls): int
    {
        $synced = $polls->sync();

        foreach ($synced['opened'] as $id) {
            $this->components->info("Opened poll [{$id}]: options frozen.");
        }

        foreach ($synced['closed'] as $id) {
            $this->components->info("Closed poll [{$id}]: results frozen.");
        }

        return self::SUCCESS;
    }
}
