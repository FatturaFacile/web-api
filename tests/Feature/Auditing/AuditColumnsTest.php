<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('every business table has the six audit columns', function (): void {
    $excluded = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'transaction_locks', 'notifications'];
    $incomplete = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (in_array($table, $excluded, true)) {
            continue;
        }
        foreach (['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $incomplete[] = "$table.$column";
            }
        }
    }

    expect($incomplete)->toBe([]);
});

test('the stamper fills the audit users from the authenticated user and leaves them null in the CLI', function (): void {
    $anonymous = Tenant::factory()->create();
    expect($anonymous->created_by)->toBeNull();

    $user = User::factory()->create();
    $this->actingAs($user);

    $tenant = Tenant::factory()->create();
    expect($tenant->created_by)->toBe($user->id)->and($tenant->updated_by)->toBe($user->id);

    $tenant->update(['name' => 'Renamed']);
    expect($tenant->fresh()->updated_by)->toBe($user->id);
});
