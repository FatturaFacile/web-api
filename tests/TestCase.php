<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $sqliteMigrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Default local setup: a file-based SQLite database (see phpunit.xml) migrated once per test run.
        // Other drivers (CI pgsql) are expected to be migrated beforehand.
        if (! self::$sqliteMigrated && config('database.default') === 'sqlite') {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$sqliteMigrated = true;
        }
    }
}
