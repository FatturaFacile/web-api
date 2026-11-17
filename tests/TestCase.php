<?php

namespace Tests;

use App\Contracts\Security\HostResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StaticHostResolver;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase {
        refreshDatabase as protected lazilyRefreshDatabase;
    }

    protected bool $seed = true;

    /**
     * Tests that use DatabaseTransactions run on an already migrated database. Letting the lazy refresh fire there would
     * migrate while the transaction trait has unset the event dispatcher, which is a TypeError in setEventDispatcher().
     */
    public function refreshDatabase()
    {
        if (in_array(DatabaseTransactions::class, class_uses_recursive(static::class), true)) {
            return;
        }

        $this->lazilyRefreshDatabase();
    }

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
