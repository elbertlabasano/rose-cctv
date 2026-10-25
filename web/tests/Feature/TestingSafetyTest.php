<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestingSafetyTest extends TestCase
{
    public function test_isolated_configuration_is_required_even_with_live_caches_present(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertNotSame(base_path('bootstrap/cache/config.php'), $this->app->getCachedConfigPath());
    }

    public function test_guard_refuses_mysql_before_any_refresh_can_run(): void
    {
        config(['database.default' => 'mysql']);
        $this->expectException(\RuntimeException::class);
        $this->assertSafeTestDatabase($this->app);
    }

    public function test_guard_refuses_persistent_sqlite_before_any_refresh_can_run(): void
    {
        config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
        $this->expectException(\RuntimeException::class);
        $this->assertSafeTestDatabase($this->app);
    }
}
