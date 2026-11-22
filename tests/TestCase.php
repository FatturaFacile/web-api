<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Security\HostResolver;
use Database\Seeders\TestingSeeder;
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

    protected string $seeder = TestingSeeder::class;

    /**
     * Tests that use DatabaseTransactions run on an already migrated database. Letting the lazy refresh fire there would
     * migrate while the transaction trait has unset the event dispatcher, which is a TypeError in setEventDispatcher().
     */
    public function refreshDatabase()
    {
        if (in_array(DatabaseTransactions::class, class_uses_recursive(static::class), true)) {
            // An in-memory SQLite database is new for every test, so a transaction-only test finds no schema: build it
            // (and seed it) eagerly, before the transaction starts. A persistent database (file) is left alone.
            if (! Schema::hasTable('tenants')) {
                $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            }

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

    protected function setUpTraits(): array
    {
        $uses = array_flip(class_uses_recursive(static::class));

        if (isset($uses[RefreshDatabase::class]) && ! RefreshDatabaseState::$migrated) {
            if (! filter_var($_ENV['TEST_MIGRATE_FRESH'] ?? $_SERVER['TEST_MIGRATE_FRESH'] ?? false, FILTER_VALIDATE_BOOL) && Schema::hasTable('migrations')) {
                $this->artisan('migrate', ['--force' => true]);
                RefreshDatabaseState::$migrated = true;
            }
        }

        return parent::setUpTraits();
    }
}
