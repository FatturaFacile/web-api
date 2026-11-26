<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Jobs\ProcessAuditLogJob;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    $this->tenantId = (string) Str::uuid7();
    $this->verifier = app(AuditChainVerificationService::class);
});

test('entries get a strictly monotonic sequence without gaps and a valid chain', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 12);

    expect($entries->pluck('sequence')->all())->toBe(range(1, 12))
        ->and($entries->every(fn (AuditLedgerEntry $e): bool => $e->hash_version === 2 && $e->key_version === 1))->toBeTrue()
        ->and($this->verifier->verifyTenantChain($this->tenantId))->toMatchArray(['valid' => true, 'total_verified' => 12]);

    $stored = AuditLedgerFixtures::entries($this->tenantId);

    foreach ($stored->skip(1) as $entry) {
        expect($entry->previous_hash)->toBe($stored->firstWhere('sequence', $entry->sequence - 1)->record_hash)
            ->and($entry->event_timestamp->greaterThan($stored->firstWhere('sequence', $entry->sequence - 1)->event_timestamp))->toBeTrue();
    }
});

test('the first entry links to the seed of the tenant and the seed differs per tenant', function (): void {
    $other = (string) Str::uuid7();
    $first = AuditLedgerFixtures::append($this->tenantId);
    $otherFirst = AuditLedgerFixtures::append($other);

    expect($first->previous_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($first->previous_hash)->not->toBe(str_repeat('0', 64))
        ->and($first->previous_hash)->not->toBe($otherFirst->previous_hash);
});

test('every tenant has its own sequence and its own chain', function (): void {
    $other = (string) Str::uuid7();

    foreach (range(1, 4) as $i) {
        AuditLedgerFixtures::append($this->tenantId);
        AuditLedgerFixtures::append($other);
    }

    expect(AuditLedgerFixtures::entries($this->tenantId)->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and(AuditLedgerFixtures::entries($other)->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and($this->verifier->verifyTenantChain($this->tenantId)['valid'])->toBeTrue()
        ->and($this->verifier->verifyTenantChain($other)['valid'])->toBeTrue();
});

test('the rollback of the business transaction leaves no orphan entry and no gap', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);

    foreach (range(1, 3) as $attempt) {
        try {
            DB::transaction(function () use ($attempt): void {
                AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['attempt' => $attempt]);

                throw new RuntimeException('the business operation failed');
            });
        } catch (RuntimeException) {
        }
    }

    expect(AuditLedgerFixtures::entries($this->tenantId)->pluck('sequence')->all())->toBe([1, 2]);

    $next = AuditLedgerFixtures::append($this->tenantId);

    expect($next->sequence)->toBe(3)
        ->and($this->verifier->verifyTenantChain($this->tenantId))->toMatchArray(['valid' => true, 'total_verified' => 3]);
});

test('the commit of the business transaction persists the entry in the same transaction', function (): void {
    DB::transaction(function (): void {
        AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['name' => 'committed']);
        AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['name' => 'committed'], ['name' => 'renamed']);
    });

    expect(AuditLedgerFixtures::entries($this->tenantId)->pluck('action')->all())->toBe(['CREATED', 'UPDATED'])
        ->and($this->verifier->verifyTenantChain($this->tenantId)['valid'])->toBeTrue();
});

test('a nested savepoint that rolls back keeps the outer entries and the sequence consistent', function (): void {
    DB::transaction(function (): void {
        AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['name' => 'outer']);

        try {
            DB::transaction(function (): void {
                AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2]);

                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
        }

        AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['name' => 'outer'], ['name' => 'after']);
    });

    expect(AuditLedgerFixtures::entries($this->tenantId)->pluck('sequence')->all())->toBe([1, 2])
        ->and($this->verifier->verifyTenantChain($this->tenantId)['valid'])->toBeTrue();
});

test('many writers of several tenants interleaved keep every sequence gapless and every chain valid', function (): void {
    $tenants = [$this->tenantId, (string) Str::uuid7(), (string) Str::uuid7()];

    foreach (range(1, 30) as $i) {
        $tenant = $tenants[$i % 3];

        // Every fifth append belongs to a business transaction that fails.
        if ($i % 5 === 0) {
            try {
                DB::transaction(function () use ($tenant): void {
                    AuditLedgerFixtures::append($tenant);

                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
            }

            continue;
        }

        AuditLedgerFixtures::append($tenant);
    }

    foreach ($tenants as $tenant) {
        $sequences = AuditLedgerFixtures::entries($tenant)->pluck('sequence')->all();

        expect($sequences)->toBe(range(1, count($sequences)))
            ->and($this->verifier->verifyTenantChain($tenant)['valid'])->toBeTrue();
    }
});

test('the database refuses a second entry with the same sequence of a tenant', function (): void {
    if (DB::getDriverName() === 'pgsql') {
        $this->markTestSkipped('PostgreSQL partitions the ledger by time: the per-tenant lock keeps the sequence unique there.');
    }

    $first = AuditLedgerFixtures::append($this->tenantId);
    $duplicate = $first->only(['tenant_id', 'company_id', 'module_code', 'action', 'resource_type', 'resource_id', 'actor_type', 'record_hash', 'previous_hash']);

    expect(fn () => DB::table('enterprise_audit_ledger')->insert($duplicate + [
        'id' => (string) Str::uuid7(),
        'event_timestamp' => now()->addSecond()->format('Y-m-d H:i:s.uP'),
        'sequence' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a mutation whose entry cannot be written fails closed', function (): void {
    AuditLedgerEntry::creating(static function (): void {
        throw new RuntimeException('the ledger is unavailable');
    });
    Queue::fake();

    $payload = ['tenant_id' => $this->tenantId, 'module_code' => 'CRM', 'model_class' => 'App\\Models\\Contact', 'model_id' => (string) Str::uuid7()];

    expect(fn () => app(AuditLedgerInterface::class)->recordPayload($payload + ['action' => 'UPDATED']))
        ->toThrow(RuntimeException::class, 'the ledger is unavailable')
        ->and(fn () => app(AuditLedgerInterface::class)->recordPayload($payload + ['action' => 'SDI_SENT']))
        ->toThrow(RuntimeException::class);

    Queue::assertNotPushed(ProcessAuditLogJob::class);
});

test('a read only view that cannot be written is queued for retry instead of blocking the request', function (): void {
    AuditLedgerEntry::creating(static function (): void {
        throw new RuntimeException('the ledger is unavailable');
    });
    Queue::fake();

    app(AuditLedgerInterface::class)->recordPayload([
        'tenant_id' => $this->tenantId,
        'module_code' => 'CRM',
        'action' => 'VIEWED',
        'model_class' => 'App\\Models\\Contact',
        'model_id' => (string) Str::uuid7(),
    ]);

    Queue::assertPushed(ProcessAuditLogJob::class, static fn (ProcessAuditLogJob $job): bool => $job->data['action'] === 'VIEWED' && $job->data['tenant_id'] !== '');
});

test('the retry job appends the queued entry once the ledger is back', function (): void {
    $payload = [
        'tenant_id' => $this->tenantId, 'company_id' => $this->tenantId, 'module_code' => 'CRM', 'action' => 'VIEWED',
        'model_class' => 'App\\Models\\Contact', 'model_id' => (string) Str::uuid7(),
    ];

    (new ProcessAuditLogJob($payload))->handle(app(RecordAuditAction::class));

    expect(AuditLedgerFixtures::entries($this->tenantId))->toHaveCount(1)
        ->and(AuditLedgerFixtures::entries($this->tenantId)->first()->category)->toBe('access');
});

test('an entry carries the category, the channel, the context and the occurrence in utc', function (): void {
    $entry = AuditLedgerFixtures::append(
        $this->tenantId, 'LOGIN_FAILED', null, ['guard' => 'web'], module: 'AUTH',
        client: new ClientContext('mobile', 'req-77', '203.0.113.0', 'Agent/1.0'),
        context: ['reason' => 'bad_password'],
    );

    expect($entry->category)->toBe('security')
        ->and($entry->channel)->toBe('mobile')
        ->and($entry->request_id)->toBe('req-77')
        ->and($entry->ip_address)->toBe('203.0.113.0')
        ->and($entry->context)->toMatchArray(['channel' => 'mobile', 'request_id' => 'req-77', 'reason' => 'bad_password'])
        ->and($entry->actor_type)->toBe('system')
        ->and($entry->event_timestamp->getTimezone()->getName())->toBeIn(['UTC', '+00:00'])
        ->and($entry->resource_id)->toMatch('/^[0-9a-f-]{36}$/');
});

test('a resource that is not identified by a uuid keeps its reference in the context', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], 'INV-2027-0042');

    expect($entry->resource_id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($entry->context['resource_ref'])->toBe('INV-2027-0042');
});

test('the context and the changes of an entry are stored in canonical form', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['total' => 10.5, 'z' => 1], ['total' => 12.25, 'z' => 1]);

    expect($entry->changes)->toBe(['new' => ['total' => '12.25'], 'old' => ['total' => '10.5']]);
});
