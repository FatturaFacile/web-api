<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * For tests that need a database without tenants and users.
 *
 * The database of a test process is built and seeded once, by the first test that runs, and every test then works in a
 * rolled back transaction on top of it. So the baseline tenants, users and memberships of the testing seeder cannot be
 * skipped per class: they are removed inside the transaction of the test, the reference data (lookups, payroll
 * parameters) stays. Laravel calls this hook of a test that uses the trait.
 */
trait WithoutSeeding
{
    protected function setUpWithoutSeeding(): void
    {
        DB::table('tenant_user')->delete();
        DB::table('users')->delete();
        DB::table('tenants')->delete();
    }
}
