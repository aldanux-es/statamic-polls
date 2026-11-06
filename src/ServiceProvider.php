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
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Polls::class,
    ];

    protected $scopes = [
        Filters\PollStatus::class,
        Filters\PollGroup::class,
        Filters\PollSite::class,
    ];

    protected $commands = [
        InstallPolls::class,
        SyncPolls::class,
        DuplicatePoll::class,
        MigrateVotes::class,
    ];

    protected $routes = [
        'actions' => __DIR__.'/../routes/actions.php',
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/addon.js'],
        'publicDirectory' => 'resources/dist',
    ];

    protected $publishables = [
        __DIR__.'/../resources/js/polls.js' => 'js/polls.js',
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

        Permission::extend(function (): void {
            Permission::group('statamic-polls', __('statamic-polls::cp.title'), function (): void {
                Permission::register('view polls')->label(__('statamic-polls::cp.permission_view'))->children([
                    Permission::make('void poll votes')->label(__('statamic-polls::cp.permission_void')),
                ]);
            });
        });

        Nav::extend(function (Navigation $nav): void {
            $nav->findOrCreate('Content', __('statamic-polls::cp.nav'))
                ->route('statamic-polls.index')
                ->icon('charts-donut-graph')
                ->can('view polls');
        });
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('polls:sync')->everyMinute()->withoutOverlapping();
    }
}
