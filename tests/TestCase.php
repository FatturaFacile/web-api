<?php

namespace Tests;

use App\Contracts\Security\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StaticHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        if (gc_enabled()) {
            gc_collect_cycles();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Outbound URLs are resolved before every request: tests never reach the real DNS.
        $this->app->instance(HostResolver::class, new StaticHostResolver);
    }

    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        if (isset($uses[RefreshDatabase::class]) && ! RefreshDatabaseState::$migrated) {
            if (! filter_var(env('TEST_MIGRATE_FRESH', false), FILTER_VALIDATE_BOOL) && Schema::hasTable('migrations')) {
                $this->artisan('migrate', ['--force' => true]);
                RefreshDatabaseState::$migrated = true;
            }
        }

        return parent::setUpTraits();
    }
}
