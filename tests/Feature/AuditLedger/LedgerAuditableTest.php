<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Domain\ValueObjects\PseudonymToken;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A model that uses the Auditable trait with an excluded and a personal attribute.
 */
final class AuditableProbe extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'audit_ledger_probes';

    protected $guarded = [];

    /** @var list<string> */
    protected array $auditExclude = ['counter'];

    /** @var list<string> */
    protected array $auditPersonal = ['email'];
}

beforeEach(function (): void {
    Schema::create('audit_ledger_probes', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->integer('counter')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    $this->tenantId = (string) Str::uuid7();
    $this->probe = AuditableProbe::create(['tenant_id' => $this->tenantId, 'name' => 'First', 'email' => 'probe@example.test', 'password' => 'hunter2-probe-password', 'counter' => 5]);
});

/**
 * @return list<string>
 */
function probeActions(string $tenantId): array
{
    return AuditLedgerEntry::query()->where('tenant_id', $tenantId)->orderBy('sequence')->pluck('action')->all();
}

test('a created record is audited with its attributes, without the excluded and the secret ones', function (): void {
    $entry = AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->firstOrFail();

    expect($entry->action)->toBe('CREATED')
        ->and($entry->category)->toBe('crud')
        ->and($entry->resource_id)->toBe($this->probe->id)
        ->and($entry->changes['new']['name'])->toBe('First')
        ->and($entry->changes['new'])->not->toHaveKey('counter')
        ->and($entry->changes['new']['password'])->toBe('[REDACTED]')
        ->and(PseudonymToken::looksLikeToken($entry->changes['new']['email']))->toBeTrue()
        ->and(json_encode($entry->changes))->not->toContain('probe@example.test')->and(json_encode($entry->changes))->not->toContain('hunter2-probe-password');
});

test('an update records the old and the new value of the attributes that changed only', function (): void {
    $this->probe->update(['name' => 'Second', 'email' => 'other@example.test']);

    $entry = AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'UPDATED')->firstOrFail();

    expect(array_keys($entry->changes['new']))->toBe(['email', 'name'])
        ->and($entry->changes['old']['name'])->toBe('First')
        ->and($entry->changes['new']['name'])->toBe('Second')
        ->and($entry->changes['new']['email'])->not->toBe($entry->changes['old']['email'])
        ->and(PseudonymToken::looksLikeToken($entry->changes['new']['email']))->toBeTrue();
});

test('a change of an excluded attribute and a touch make no entry', function (): void {
    $this->probe->update(['counter' => 99]);
    $this->probe->touch();

    expect(probeActions($this->tenantId))->toBe(['CREATED']);
});

test('a soft delete, a restore and a hard delete are three distinct events', function (): void {
    $this->probe->delete();
    $this->probe->restore();
    $this->probe->forceDelete();

    expect(probeActions($this->tenantId))->toBe(['CREATED', 'DELETED', 'RESTORED', 'DELETED']);

    $deletes = AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'DELETED')->orderBy('sequence')->get();

    expect($deletes[0]->context['delete_mode'])->toBe('soft')
        ->and($deletes[1]->context['delete_mode'])->toBe('hard')
        ->and($deletes[0]->changes['old']['name'])->toBe('First');
});

test('the history of a record is one chain that verifies', function (): void {
    $this->probe->update(['name' => 'Second']);
    $this->probe->delete();

    expect(app(AuditChainVerificationService::class)->verifyTenantChain($this->tenantId))->toMatchArray(['valid' => true, 'total_verified' => 3])
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->pluck('resource_id')->unique()->all())->toBe([$this->probe->id]);
});

test('a model without a tenant writes to the system chain', function (): void {
    $global = AuditableProbe::create(['name' => 'Global']);

    expect(AuditLedgerEntry::query()->where('resource_id', $global->id)->value('tenant_id'))->not->toBeNull();
});
