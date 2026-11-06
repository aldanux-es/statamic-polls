<?php

namespace Aldanux\StatamicPolls;

use Aldanux\StatamicPolls\Commands\DuplicatePoll;
use Aldanux\StatamicPolls\Commands\InstallPolls;
use Aldanux\StatamicPolls\Commands\MigrateVotes;
use Aldanux\StatamicPolls\Commands\SyncPolls;
use Aldanux\StatamicPolls\Tags\Polls;
use Aldanux\StatamicPolls\Votes\DatabaseVoteRepository;
use Aldanux\StatamicPolls\Votes\FileVoteRepository;
use Aldanux\StatamicPolls\Votes\VoteRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Polls::class,
    ];

    protected $commands = [
        InstallPolls::class,
        SyncPolls::class,
        DuplicatePoll::class,
        MigrateVotes::class,
    ];

    protected $routes = [
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    protected $publishables = [
        __DIR__.'/../resources/js' => 'js',
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PollManager::class);

        $this->app->bind(VoteRepository::class, fn ($app): VoteRepository => $this->repository(
            (string) config('statamic-polls.driver', 'file'),
        ));
    }

    /**
     * Builds the repository of a driver ("file" or "database"). Public so the
     * polls:migrate-votes command can build both ends.
     */
    public function repository(string $driver): VoteRepository
    {
        return match ($driver) {
            'file' => new FileVoteRepository(
                $this->app->make(Filesystem::class),
                (string) config('statamic-polls.drivers.file.path', storage_path('statamic-polls')),
            ),
            'database' => new DatabaseVoteRepository(
                $this->app->make('db')->connection(config('statamic-polls.drivers.database.connection')),
                (string) config('statamic-polls.drivers.database.table', 'poll_votes'),
            ),
            default => throw new InvalidArgumentException("Unknown polls driver [{$driver}]."),
        };
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-polls-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-polls'),
        ], 'statamic-polls-views');
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('polls:sync')->everyMinute()->withoutOverlapping();
    }
}
