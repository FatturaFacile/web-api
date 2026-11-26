<?php

declare(strict_types=1);

use App\Support\Database\Immutability;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Search Tenant');
    ['tenant' => $this->other, 'user' => $this->otherOwner] = AuditLedgerFixtures::tenant('Other Search Tenant');
    $this->tenantId = (string) $this->tenant->id;
    $this->base = '/api/v1/settings/audit-logs';

    // Start from a known chain: the entries the fixtures of the tenants wrote on creation are not part of the scenarios.
    $this->resource = (string) Str::uuid7();
    $this->baseline = (int) AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->max('sequence');
});

test('the endpoint needs a signed in member of the tenant with an auditor role', function (): void {
    $this->getJson($this->base)->assertUnauthorized();

    AuditLedgerFixtures::actingAs(AuditLedgerFixtures::member($this->tenant, 'member'), $this->tenant)->getJson($this->base)->assertForbidden();
    AuditLedgerFixtures::actingAs(AuditLedgerFixtures::member($this->tenant, 'accountant'), $this->tenant)->getJson($this->base)->assertForbidden();
});

test('owner, admin, external auditor and dpo can search', function (string $role): void {
    $user = $role === 'owner' ? $this->owner : AuditLedgerFixtures::member($this->tenant, $role);
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], $this->resource);

    AuditLedgerFixtures::actingAs($user, $this->tenant)->getJson($this->base.'?resource_type=Contact')
        ->assertOk()
        ->assertJsonStructure(['success', 'message', 'data' => [['id', 'sequence', 'occurred_at', 'action', 'category', 'resource_type', 'resource_id', 'actor_type', 'display_actor', 'changes' => ['old', 'new'], 'context', 'previous_hash', 'hash', 'key_version']], 'meta' => ['next_cursor', 'limit']]);
})->with(['owner', 'admin', 'external_auditor', 'dpo']);

test('the cursor pages through every entry newest first without repeating or skipping one', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 7, $this->resource);
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $query = '?limit=3&resource_type=Contact'.($cursor === null ? '' : '&cursor='.$cursor);
        $response = $client->getJson($this->base.$query)->assertOk();
        $seen = array_merge($seen, array_column($response->json('data'), 'sequence'));
        $cursor = $response->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    $expected = range($this->baseline + 7, $this->baseline + 1);

    expect($pages)->toBe(3)
        ->and($seen)->toBe($expected)
        ->and(array_unique($seen))->toHaveCount(7);
});

test('the filters narrow the search', function (): void {
    $actor = AuditLedgerFixtures::member($this->tenant, 'admin');
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['n' => 1], $this->resource, 'App\\Models\\Contact', $actor->id);
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['n' => 1], ['n' => 2], $this->resource, 'App\\Models\\Invoice', null, [], 'INVOICING', new ClientContext('mobile', 'r1', '203.0.113.0', 'UA'));
    AuditLedgerFixtures::append($this->tenantId, 'DELETED', ['n' => 2], null, (string) Str::uuid7(), 'App\\Models\\Contact', null, [], 'CRM', new ClientContext('web', 'r2', '198.51.100.0', 'UA'));
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $sequences = fn (string $query): array => array_column($client->getJson($this->base.'?'.$query)->assertOk()->json('data'), 'action');

    expect($sequences('action=created&resource_type=Contact'))->toBe(['CREATED'])
        ->and($sequences('resource_type=Invoice'))->toBe(['UPDATED'])
        ->and($sequences('resource_type='.urlencode('App\\Models\\Invoice')))->toBe(['UPDATED'])
        ->and($sequences('channel=mobile'))->toBe(['UPDATED'])
        ->and($sequences('channel=web&action=DELETED'))->toBe(['DELETED'])
        ->and($sequences('ip=203.0.113.77'))->toBe(['UPDATED'])
        ->and($sequences('actor='.$actor->id))->toBe(['CREATED'])
        ->and($sequences('company_id='.$this->tenantId.'&action=DELETED'))->toBe(['DELETED'])
        ->and($sequences('company_id='.Str::uuid7()))->toBe([])
        ->and($sequences('category=crud&action=CREATED&resource_type=Contact'))->toBe(['CREATED']);
});

test('the date range filters on utc days', function (): void {
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], $this->resource);
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);
    $today = now('UTC')->toDateString();

    expect($client->getJson($this->base.'?resource_type=Contact&from='.$today.'&to='.$today)->assertOk()->json('data'))->not->toBeEmpty()
        ->and($client->getJson($this->base.'?resource_type=Contact&from='.now('UTC')->addDay()->toDateString())->assertOk()->json('data'))->toBe([])
        ->and($client->getJson($this->base.'?resource_type=Contact&to='.now('UTC')->subDay()->toDateString())->assertOk()->json('data'))->toBe([]);
});

test('a tenant never sees the entries of another tenant', function (): void {
    $secret = (string) Str::uuid7();
    AuditLedgerFixtures::append((string) $this->other->id, 'UPDATED', ['a' => 1], ['a' => 2], $secret);
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], $this->resource);

    $rows = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'?limit=200')->assertOk()->json('data');

    expect(array_unique(array_column($rows, 'tenant_id')))->toBe([$this->tenantId])
        ->and(array_column($rows, 'resource_id'))->not->toContain($secret);

    // The owner of the other tenant, acting in the first, has no auditor role there.
    AuditLedgerFixtures::actingAs($this->otherOwner, $this->tenant)->getJson($this->base)->assertForbidden();
});

test('bad filters and a window longer than 366 days are rejected', function (string $query): void {
    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'?'.$query)->assertUnprocessable();
})->with([
    'from=02/01/2027',
    'from=2027-02-01&to=2027-01-01',
    'from=2027-01-01&to=2028-01-03',
    'channel=fax',
    'ip=nope',
    'limit=1000',
    'company_id=nope',
]);

test('the history of a record lists its changes for a member who may read it', function (): void {
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['company_name' => 'Acme'], $this->resource);
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['company_name' => 'Acme'], ['company_name' => 'Acme SA'], $this->resource);
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['x' => 1], ['x' => 2], (string) Str::uuid7());
    $member = AuditLedgerFixtures::member($this->tenant, 'member');

    $response = AuditLedgerFixtures::actingAs($member, $this->tenant)
        ->getJson('/api/v1/audit/history/Contact/'.$this->resource)
        ->assertOk()
        ->assertJsonPath('data.0.action', 'UPDATED')
        ->assertJsonPath('data.0.changed_fields', ['company_name'])
        ->assertJsonPath('data.0.changes.new.company_name', 'Acme SA')
        ->assertJsonPath('data.1.action', 'CREATED');

    expect($response->json('data'))->toHaveCount(2);
});

test('the history is forbidden when the member may not read the module of the record', function (): void {
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], $this->resource, 'App\\Models\\User');
    $member = AuditLedgerFixtures::member($this->tenant, 'member');

    AuditLedgerFixtures::actingAs($member, $this->tenant)->getJson('/api/v1/audit/history/User/'.$this->resource)->assertForbidden();
    AuditLedgerFixtures::actingAs($member, $this->tenant)->getJson('/api/v1/audit/history/Unknown/'.$this->resource)->assertForbidden();
    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/audit/history/User/'.$this->resource)->assertOk();
});

test('the history of a record of another tenant is empty', function (): void {
    $foreign = (string) Str::uuid7();
    AuditLedgerFixtures::append((string) $this->other->id, 'UPDATED', ['a' => 1], ['a' => 2], $foreign);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/audit/history/Contact/'.$foreign)
        ->assertOk()->assertJsonPath('data', []);
});

test('an entry of the ledger can never be edited or deleted through the api', function (string $method, string $path): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], $this->resource);
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $response = $client->json($method, str_replace('{id}', $entry->id, $path), ['action' => 'TAMPERED']);

    expect($response->status())->toBe(405)
        ->and($response->headers->get('Allow'))->toBe('GET')
        ->and(AuditLedgerEntry::query()->where('id', $entry->id)->value('action'))->toBe('UPDATED');
})->with([
    ['PUT', '/api/v1/settings/audit-logs/{id}'],
    ['PATCH', '/api/v1/settings/audit-logs/{id}'],
    ['DELETE', '/api/v1/settings/audit-logs/{id}'],
    ['POST', '/api/v1/settings/audit-logs/{id}'],
    ['PUT', '/api/v1/audit/history/Contact/{id}'],
    ['DELETE', '/api/v1/audit/history/Contact/{id}'],
]);

test('the models refuse every update and delete of an entry', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId);

    expect(fn () => $entry->update(['action' => 'X']))->toThrow(LogicException::class, 'cannot be updated')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'cannot be deleted')
        ->and(fn () => $entry->forceDelete())->toThrow(LogicException::class, 'cannot be deleted')
        ->and(fn () => $entry->fill(['record_hash' => str_repeat('0', 64)])->save())->toThrow(LogicException::class);

    // The guard also holds inside the global Immutability switch used by the other models.
    expect(fn () => Immutability::lifted(fn () => $entry->update(['action' => 'X'])))->toThrow(LogicException::class);
    expect(DB::table('enterprise_audit_ledger')->where('id', $entry->id)->value('action'))->toBe('UPDATED');
});
