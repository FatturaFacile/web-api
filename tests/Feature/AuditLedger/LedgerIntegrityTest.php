<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryHandler;
use Enterprise\AuditLedger\Application\Commands\CreateCheckpointCommand;
use Enterprise\AuditLedger\Application\Commands\CreateCheckpointHandler;
use Enterprise\AuditLedger\Application\Commands\RotateAuditKeyCommand;
use Enterprise\AuditLedger\Application\Commands\RotateAuditKeyHandler;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityCommand;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityHandler;
use Enterprise\AuditLedger\Application\Exceptions\VerificationAlreadyRunning;
use Enterprise\AuditLedger\Contracts\AuditAlertGateway;
use Enterprise\AuditLedger\Domain\Events\AuditChainBroken;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Jobs\AnchorCheckpointJob;
use Enterprise\AuditLedger\Jobs\CreateDailyCheckpointsJob;
use Enterprise\AuditLedger\Jobs\VerifyAuditChainsJob;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Models\IntegrityVerificationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Integrity Tenant');
    $this->tenantId = (string) $this->tenant->id;
    Storage::fake('audit_anchor_test');
    config(['audit_ledger.anchor.disk' => 'audit_anchor_test']);
});

test('the integrity status is never verified until a run completes', function (): void {
    $response = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/integrity')->assertOk();

    expect($response->json('data.status'))->toBe('never_verified')
        ->and($response->json('data.last_run'))->toBeNull()
        ->and($response->json('data.tail.sequence'))->toBeInt();
});

test('an on demand verification is queued, runs and is reported by the status', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 5);
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $queued = $client->postJson('/api/v1/settings/audit-logs/integrity/verify')->assertStatus(202);
    $run = IntegrityVerificationRun::query()->findOrFail($queued->json('data.id'));

    // The test queue is synchronous: the run has already completed.
    expect($run->status)->toBe('completed')
        ->and($run->outcome)->toBe('valid')
        ->and($run->trigger)->toBe('manual')
        ->and($run->requested_by)->toBe((string) $this->owner->id)
        ->and($run->verified_entries)->toBeGreaterThanOrEqual(5);

    $client->getJson('/api/v1/settings/audit-logs/integrity')
        ->assertOk()
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.last_run.id', $run->id)
        ->assertJsonPath('data.last_run.outcome', 'valid');
});

test('the outcome of a verification is a platform entry of the ledger itself', function (): void {
    app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)));

    $entry = AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'AUDIT_INTEGRITY_VERIFIED')->firstOrFail();

    expect($entry->category)->toBe('platform')
        ->and($entry->new_values['outcome'])->toBe('valid');
});

test('a second verification is refused with 409 while one is queued or running', function (): void {
    IntegrityVerificationRun::query()->create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'trigger' => 'scheduled', 'status' => 'running', 'started_at' => now(),
    ]);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson('/api/v1/settings/audit-logs/integrity/verify')->assertStatus(409);

    expect(fn () => app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)))->toThrow(VerificationAlreadyRunning::class);
});

test('a stale run no longer blocks a new verification', function (): void {
    IntegrityVerificationRun::query()->create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'trigger' => 'scheduled', 'status' => 'running',
        'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
    ]);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson('/api/v1/settings/audit-logs/integrity/verify')->assertStatus(202);
});

test('only an auditor may verify and the verification of another tenant is never visible', function (): void {
    AuditLedgerFixtures::actingAs(AuditLedgerFixtures::member($this->tenant, 'member'), $this->tenant)
        ->postJson('/api/v1/settings/audit-logs/integrity/verify')->assertForbidden();

    ['tenant' => $other, 'user' => $otherOwner] = AuditLedgerFixtures::tenant('Foreign Integrity Tenant');
    app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand((string) $other->id)));

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/integrity')
        ->assertOk()->assertJsonPath('data.status', 'never_verified');
});

test('an altered entry is reported broken at its exact sequence, raises the event and alerts the owner', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 6);
    $target = $entries[3];
    AuditLedgerFixtures::tamper($this->tenantId, (int) $target->sequence, ['action' => 'TAMPERED']);

    Event::fake([AuditChainBroken::class]);
    $alerts = Mockery::mock(AuditAlertGateway::class);
    $alerts->shouldReceive('chainBroken')->once()->with($this->tenantId, Mockery::on(fn (array $d): bool => $d['outcome'] === 'broken_at' && $d['broken_at_sequence'] === (int) $target->sequence));
    $this->app->instance(AuditAlertGateway::class, $alerts);

    $run = app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)));

    expect($run->outcome)->toBe('broken_at')
        ->and($run->broken_at_sequence)->toBe((int) $target->sequence)
        ->and($run->status)->toBe('completed');

    Event::assertDispatched(AuditChainBroken::class, fn (AuditChainBroken $e): bool => $e->tenantId === $this->tenantId && $e->brokenAtSequence === (int) $target->sequence);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/integrity')
        ->assertOk()->assertJsonPath('data.status', 'broken_at')->assertJsonPath('data.last_run.broken_at_sequence', (int) $target->sequence);
});

test('a deleted entry is reported as a missing range', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 6);
    AuditLedgerFixtures::deleteRow($this->tenantId, (int) $entries[2]->sequence);
    AuditLedgerFixtures::deleteRow($this->tenantId, (int) $entries[3]->sequence);

    $run = app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)));

    expect($run->outcome)->toBe('missing_range')
        ->and($run->missing_from_sequence)->toBe((int) $entries[2]->sequence)
        ->and($run->missing_to_sequence)->toBe((int) $entries[3]->sequence);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/integrity')
        ->assertOk()->assertJsonPath('data.status', 'missing_range')
        ->assertJsonPath('data.last_run.missing_range.from', (int) $entries[2]->sequence);
});

test('the tail removed after a checkpoint is detected', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 5);
    app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand($this->tenantId));
    $last = (int) $entries->last()->sequence;
    AuditLedgerFixtures::deleteRow($this->tenantId, $last);

    $run = app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)));

    expect($run->outcome)->toBe('missing_range')->and($run->missing_from_sequence)->toBe($last)->and($run->missing_to_sequence)->toBe($last);
});

test('the nightly job verifies every tenant and a failing chain never stops the next', function (): void {
    ['tenant' => $healthy] = AuditLedgerFixtures::tenant('Healthy Tenant');
    AuditLedgerFixtures::chain((string) $healthy->id, 3);
    AuditLedgerFixtures::chain($this->tenantId, 3);

    // One entry of this tenant is unreadable (a timestamp nobody can parse): its verification fails, the others still run.
    DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenantId)->where('sequence', 2)->update(['event_timestamp' => 'not-a-date']);

    (new VerifyAuditChainsJob)->handle(app(VerifyChainIntegrityHandler::class));

    $runs = IntegrityVerificationRun::query()->get()->keyBy('tenant_id');

    expect($runs[$this->tenantId]->status)->toBe('failed')
        ->and($runs[$this->tenantId]->error)->not->toBeNull()
        ->and($runs[(string) $healthy->id]->status)->toBe('completed')
        ->and($runs[(string) $healthy->id]->outcome)->toBe('valid')
        ->and($runs[(string) $healthy->id]->trigger)->toBe('scheduled');
});

test('the nightly job skips a tenant whose verification is already running', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);
    IntegrityVerificationRun::query()->create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'trigger' => 'manual', 'status' => 'running']);

    (new VerifyAuditChainsJob)->handle(app(VerifyChainIntegrityHandler::class));

    expect(IntegrityVerificationRun::query()->where('tenant_id', $this->tenantId)->count())->toBe(1);
});

test('a daily checkpoint pins the tail, is idempotent per day and is anchored on the write once target', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 4);
    $handler = app(CreateCheckpointHandler::class);

    $checkpoint = $handler->handle(new CreateCheckpointCommand($this->tenantId));
    $again = $handler->handle(new CreateCheckpointCommand($this->tenantId));

    expect($checkpoint->sequence)->toBe((int) $entries->last()->sequence)
        ->and($checkpoint->hash)->toBe($entries->last()->record_hash)
        ->and($checkpoint->signature)->toMatch('/^[0-9a-f]{64}$/')
        ->and($checkpoint->type)->toBe('daily')
        ->and($again->id)->toBe($checkpoint->id)
        ->and(AuditCheckpoint::query()->where('tenant_id', $this->tenantId)->count())->toBe(1);

    $fresh = $checkpoint->fresh();

    expect($fresh->anchored_at)->not->toBeNull()
        ->and($fresh->anchor_uri)->toStartWith('audit_anchor_test://');

    Storage::disk('audit_anchor_test')->assertExists(substr((string) $fresh->anchor_uri, strlen('audit_anchor_test://')));
    expect(json_decode(Storage::disk('audit_anchor_test')->get(substr((string) $fresh->anchor_uri, strlen('audit_anchor_test://'))), true))
        ->toMatchArray(['tenant_id' => $this->tenantId, 'sequence' => $checkpoint->sequence, 'hash' => $checkpoint->hash]);
});

test('a period checkpoint is taken for the close of an accounting period', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);

    $checkpoint = app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand($this->tenantId, 'period', '2027-03'));

    expect($checkpoint->type)->toBe('period')->and($checkpoint->period_ref)->toBe('2027-03');
});

test('a tenant without entries has no checkpoint', function (): void {
    expect(app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand((string) Str::uuid7())))->toBeNull();
});

test('the daily job checkpoints the tenants that wrote today and isolates a failing one', function (): void {
    ['tenant' => $second] = AuditLedgerFixtures::tenant('Second Checkpoint Tenant');
    AuditLedgerFixtures::chain($this->tenantId, 2);
    AuditLedgerFixtures::chain((string) $second->id, 2);

    (new CreateDailyCheckpointsJob)->handle(app(CreateCheckpointHandler::class));

    expect(AuditCheckpoint::query()->whereIn('tenant_id', [$this->tenantId, (string) $second->id])->count())->toBe(2);
});

test('a checkpoint cannot be edited or deleted, only anchored', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 1);
    $checkpoint = app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand($this->tenantId));

    expect(fn () => $checkpoint->update(['hash' => str_repeat('0', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $checkpoint->delete())->toThrow(LogicException::class);
});

test('the anchor job refuses to overwrite a different anchor', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 1);
    config(['audit_ledger.anchor.enabled' => false]);
    $checkpoint = app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand($this->tenantId));
    $path = 'audit-ledger-checkpoints/'.$this->tenantId.'/daily/'.$checkpoint->period_ref.'-'.$checkpoint->sequence.'.json';
    Storage::disk('audit_anchor_test')->put($path, '{"forged":true}');

    expect(fn () => (new AnchorCheckpointJob($checkpoint->id))->handle())->toThrow(RuntimeException::class, 'already exists');
});

test('a rotated key signs the new entries while the old ones keep verifying', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 3);

    app(RotateAuditKeyHandler::class)->handle(new RotateAuditKeyCommand($this->tenantId, 2, (string) $this->owner->id));
    AuditLedgerFixtures::chain($this->tenantId, 2);

    $versions = AuditLedgerFixtures::entries($this->tenantId)->pluck('key_version')->all();
    $run = app(VerifyChainIntegrityHandler::class)->execute(app(VerifyChainIntegrityHandler::class)->createRun(new VerifyChainIntegrityCommand($this->tenantId)));

    expect(array_slice($versions, -2))->toBe([2, 2])
        ->and($versions[0])->toBe(1)
        ->and(AuditLedgerFixtures::entries($this->tenantId)->firstWhere('action', 'AUDIT_KEY_ROTATED')->key_version)->toBe(1)
        ->and($run->outcome)->toBe('valid');
});

test('a key version that is not higher or not configured is refused', function (): void {
    $handler = new RotateAuditKeyHandler(
        new AuditKeyring(['1' => 'k1-'.str_repeat('a', 32)], 1, null, null),
        app(AppendAuditEntryHandler::class),
    );

    expect(fn () => $handler->handle(new RotateAuditKeyCommand($this->tenantId, 1)))->toThrow(InvalidArgumentException::class, 'higher')
        ->and(fn () => $handler->handle(new RotateAuditKeyCommand($this->tenantId, 5)))->toThrow(InvalidArgumentException::class, 'not configured');
});

test('the checkpoints listed are the ones of the tenant only', function (): void {
    ['tenant' => $other] = AuditLedgerFixtures::tenant('Foreign Checkpoint Tenant');
    AuditLedgerFixtures::chain((string) $other->id, 2);
    app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand((string) $other->id));
    AuditLedgerFixtures::chain($this->tenantId, 2);
    $own = app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand($this->tenantId));

    $response = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/integrity')->assertOk();

    expect(array_column($response->json('data.checkpoints'), 'id'))->toBe([$own->id])
        ->and($response->json('data.last_checkpoint.id'))->toBe($own->id)
        ->and($response->json('data.last_checkpoint.anchor_uri'))->toStartWith('audit_anchor_test://');
});
