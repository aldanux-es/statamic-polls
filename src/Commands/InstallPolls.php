<?php

namespace Aldanux\StatamicPolls\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;

class InstallPolls extends Command
{
    protected $signature = 'polls:install
        {--entries=* : Collections editors may pick single entries from (e.g. players)}
        {--container= : Asset container for the images of text options}';

    protected $description = 'Create the polls collection and its blueprint';

    public function handle(): int
    {
        $handle = (string) config('statamic-polls.collection', 'polls');

        if (! Collection::find($handle)) {
            Collection::make($handle)
                ->title('Polls')
                ->sites(Site::all()->keys()->all())
                ->propagate(true)
                ->revisionsEnabled(false)
                ->save();

            $this->components->info("Collection [{$handle}] created.");
        } else {
            $this->components->warn("Collection [{$handle}] already exists, left untouched.");
        }

        $namespace = "collections.{$handle}";

        if (Blueprint::find("{$namespace}.poll")) {
            $this->components->warn("Blueprint [{$namespace}.poll] already exists, left untouched.");

            return self::SUCCESS;
        }

        $container = $this->option('container') ?: AssetContainer::all()->first()?->handle();
        $entries = array_values(array_filter((array) $this->option('entries')));

        $yaml = strtr((string) file_get_contents(__DIR__.'/../../resources/stubs/poll.yaml'), [
            '{{ entry_collections }}' => json_encode($entries),
            '{{ container }}' => $container ? json_encode($container) : 'null',
        ]);

        Blueprint::make('poll')
            ->setNamespace($namespace)
            ->setContents(YAML::parse($yaml))
            ->save();

        $this->components->info("Blueprint [{$namespace}.poll] created.");

        return self::SUCCESS;
    }
}
