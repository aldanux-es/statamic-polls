<?php

namespace Aldanux\StatamicPolls\Tests;

use Aldanux\StatamicPolls\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
