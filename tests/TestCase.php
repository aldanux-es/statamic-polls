<?php

namespace Aldanux\StatamicPolls\Tests;

use Aldanux\StatamicPolls\PollManager;
use Aldanux\StatamicPolls\ServiceProvider;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A flat-file Statamic (no entries database) with a `polls` collection using the shipped
 * blueprint and a `people` collection to pick answers from. Votes go to a temporary folder.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $votesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->votesPath = sys_get_temp_dir().'/statamic-polls-tests-'.uniqid();
        config(['statamic-polls.driver' => 'file', 'statamic-polls.drivers.file.path' => $this->votesPath]);
        app()->forgetInstance(PollManager::class);

        Blueprint::setDirectory(__DIR__.'/__fixtures__/blueprints');
        Collection::make('people')->save();
        Collection::make('polls')->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->votesPath);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('statamic.editions.pro', true);
    }
}
