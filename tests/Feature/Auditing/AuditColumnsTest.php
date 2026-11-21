<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('every business table has the six audit columns', function (): void {
    $excluded = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'password_reset_tokens', 'transaction_locks', 'notifications',
        // Secret ballot: a vote carries no user, no timestamp and nothing that could link it to its voter.
        'assoc_ballot_votes',
        // Anonymous engagement survey: a response and its answers do not name or time-stamp who answered.
        'tl_survey_responses', 'tl_survey_answers',
        // Immutable audit trail: records are inserted once and never updated or deleted (database triggers), the actor is
        // a snapshot inside the record, and the WORM batch tables are written once when a batch is sealed.
        'audit_logs', 'audit_worm_batches', 'audit_worm_batch_entries',
        // Preservation audit trail: append-only and hash-chained, so it carries no update or delete stamps.
        'preservation_audit_logs',
        // AI audit trail: append-only and hash-chained (the model refuses updates and deletes), so it carries no update or delete stamps.
        'ai_audit_trails',
        // Data residency evidence (Mod 381): the KMS access log, the blocked egress alerts and the signed attestations are append-only
        // (the models refuse updates and deletes), so they carry no update or delete stamps.
        'core_byok_key_access_audit', 'core_cross_border_blocked_attempts', 'core_sovereignty_attestations',
        // Offline device audit log: append-only and hash-chained per device, so it carries no soft delete or user stamps.
        'offline_audit_entries'];
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

test('attaching and updating a tenant membership stamps the pivot audit users and leaves them null in the CLI', function (): void {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create();
    $cliMember = User::factory()->create();

    $tenant->users()->attach($cliMember->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    expect(DB::table('tenant_user')->where('user_id', $cliMember->id)->value('created_by'))->toBeNull();

    $actor = User::factory()->create();
    $this->actingAs($actor);

    $tenant->users()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $row = DB::table('tenant_user')->where('user_id', $member->id)->first();
    expect($row->created_by)->toBe($actor->id)->and($row->updated_by)->toBe($actor->id);

    $other = User::factory()->create();
    $this->actingAs($other);
    $tenant->users()->updateExistingPivot($member->id, ['role' => 'admin']);
    $row = DB::table('tenant_user')->where('user_id', $member->id)->first();
    expect($row->role)->toBe('admin')->and($row->created_by)->toBe($actor->id)->and($row->updated_by)->toBe($other->id);

    $tenant->users()->detach($member->id);
    expect(DB::table('tenant_user')->where('user_id', $member->id)->exists())->toBeFalse();
});
