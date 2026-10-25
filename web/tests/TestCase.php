<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $this->assertSafeTestDatabase($app);

        return $app;
    }

    protected function assertSafeTestDatabase($app): void
    {
        // Fail before RefreshDatabase can run a migration against a persistent database.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.driver') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require the testing environment and SQLite :memory:. Refusing to run against persistent data.');
        }
    }
}
