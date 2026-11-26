<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Contracts\PackageSigner;
use Enterprise\AuditLedger\Models\AuditExport;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Export Tenant');
    ['tenant' => $this->other, 'user' => $this->otherOwner] = AuditLedgerFixtures::tenant('Foreign Export Tenant');
    $this->tenantId = (string) $this->tenant->id;
    $this->base = '/api/v1/settings/audit-logs/exports';
    Storage::fake('audit_export_test');
    config(['audit_ledger.export.disk' => 'audit_export_test']);
    $this->window = ['from' => now('UTC')->subDay()->toDateString(), 'to' => now('UTC')->addDay()->toDateString()];
});

/**
 * @return array<string, string> the files of the package by name
 */
function auditPackageFiles(AuditExport $export): array
{
    $path = Storage::disk((string) $export->disk)->path((string) $export->path);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $files;
}

test('an export is queued, built and described by its status', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 4);

    $response = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window)->assertStatus(202);
    $export = AuditExport::query()->findOrFail($response->json('data.id'));

    // The test queue is synchronous: the package has already been built.
    expect($export->status)->toBe('completed')
        ->and($export->entries_count)->toBeGreaterThanOrEqual(4)
        ->and($export->sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($export->signature)->not->toBeNull()
        ->and($export->verification_outcome)->toBe('valid')
        ->and($export->from_sequence)->toBeLessThanOrEqual($export->to_sequence);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'/'.$export->id)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.sha256', $export->sha256)
        ->assertJsonPath('data.start_hash', $export->start_hash)
        ->assertJsonPath('data.end_hash', $export->end_hash);
});

test('the package holds csv, json, a pdf summary and a manifest that agrees with them', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 3);
    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window)->json('data.id'));

    $files = auditPackageFiles($export);
    $manifest = json_decode($files['manifest.json'], true);
    $entries = json_decode($files['entries.json'], true)['entries'];
    $csvLines = array_filter(explode("\n", trim($files['entries.csv'])));

    expect(array_keys($files))->toEqualCanonicalizing(['entries.csv', 'entries.json', 'summary.pdf', 'manifest.json'])
        ->and(str_starts_with($files['summary.pdf'], '%PDF'))->toBeTrue()
        ->and($manifest['entries_count'])->toBe(count($entries))
        ->and(count($csvLines))->toBe(count($entries) + 1)
        ->and($manifest['from_sequence'])->toBe($entries[0]['sequence'])
        ->and($manifest['to_sequence'])->toBe($entries[count($entries) - 1]['sequence'])
        ->and($manifest['start_hash'])->toBe($entries[0]['hash'])
        ->and($manifest['end_hash'])->toBe($entries[count($entries) - 1]['hash'])
        ->and($manifest['chain_verification']['outcome'])->toBe('valid')
        ->and($manifest['files']['entries.csv'])->toBe(hash('sha256', $files['entries.csv']))
        ->and($manifest['files']['entries.json'])->toBe(hash('sha256', $files['entries.json']))
        ->and($manifest['files']['summary.pdf'])->toBe(hash('sha256', $files['summary.pdf']))
        ->and($manifest['tenant_id'])->toBe($this->tenantId);
});

test('the sha-256 of the package is the one of the stored file and its signature verifies', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);
    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window)->json('data.id'));

    $stored = Storage::disk((string) $export->disk)->get((string) $export->path);

    expect(hash('sha256', (string) $stored))->toBe($export->sha256)
        ->and(app(PackageSigner::class)->verify($this->tenantId, (string) $export->sha256, (string) $export->signature))->toBeTrue();
});

test('the package never carries the entries of another tenant', function (): void {
    $foreign = (string) Str::uuid7();
    AuditLedgerFixtures::append((string) $this->other->id, 'UPDATED', ['a' => 1], ['a' => 2], $foreign);
    AuditLedgerFixtures::chain($this->tenantId, 2);
    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window)->json('data.id'));

    $entries = json_decode(auditPackageFiles($export)['entries.json'], true)['entries'];

    expect(array_column($entries, 'resource_id'))->not->toContain($foreign);
});

test('the filters of the request narrow the package', function (): void {
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['a' => 1], (string) Str::uuid7());
    AuditLedgerFixtures::append($this->tenantId, 'DELETED', ['a' => 1], null, (string) Str::uuid7());
    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window + ['action' => 'DELETED', 'resource_type' => 'Contact'])->json('data.id'));

    $entries = json_decode(auditPackageFiles($export)['entries.json'], true)['entries'];

    expect($entries)->toHaveCount(1)->and($entries[0]['action'])->toBe('DELETED');
});

test('spreadsheet formulas in a cell are neutralised in the csv', function (): void {
    AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['note' => 'a'], ['note' => 'b'], (string) Str::uuid7(), module: '=CMD|calc');
    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window + ['action' => 'UPDATED'])->json('data.id'));

    $csv = auditPackageFiles($export)['entries.csv'];

    expect($csv)->toContain("'=CMD|calc")->and($csv)->not->toMatch('/(^|,)=CMD/m');
});

test('the same idempotency key returns the first export instead of building another', function (): void {
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $first = $client->withHeader('Idempotency-Key', 'export-2027-q1')->postJson($this->base, $this->window)->assertStatus(202);
    $second = $client->withHeader('Idempotency-Key', 'export-2027-q1')->postJson($this->base, $this->window)->assertStatus(202);

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('meta.idempotent_replay'))->toBeTrue()
        ->and($first->json('meta.idempotent_replay'))->toBeFalse()
        ->and(AuditExport::query()->where('tenant_id', $this->tenantId)->count())->toBe(1);
});

test('a window longer than 366 days is refused with 422', function (): void {
    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)
        ->postJson($this->base, ['from' => '2026-01-01', 'to' => '2027-12-31'])
        ->assertUnprocessable();

    expect(AuditExport::query()->count())->toBe(0);
});

test('an export needs a window', function (): void {
    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, [])->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
});

test('only an auditor may export and an export of another tenant is never visible', function (): void {
    AuditLedgerFixtures::actingAs(AuditLedgerFixtures::member($this->tenant, 'accountant'), $this->tenant)->postJson($this->base, $this->window)->assertForbidden();

    $export = AuditExport::query()->findOrFail(AuditLedgerFixtures::actingAs($this->otherOwner, $this->other)->postJson($this->base, $this->window)->json('data.id'));

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'/'.$export->id)->assertNotFound();
    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'/'.$export->id.'/download')->assertNotFound();
});

test('the package downloads with its hash and signature in the headers and the download is recorded', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);
    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);
    $export = AuditExport::query()->findOrFail($client->postJson($this->base, $this->window)->json('data.id'));

    $response = $client->get($this->base.'/'.$export->id.'/download')->assertOk();

    expect($response->headers->get('X-Audit-Package-SHA256'))->toBe($export->sha256)
        ->and($response->headers->get('X-Audit-Package-Signature'))->toBe($export->signature)
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'AUDIT_EXPORT_DOWNLOADED')->count())->toBe(1)
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'AUDIT_EXPORT_REQUESTED')->count())->toBe(1);
});

test('an export that is not completed cannot be downloaded', function (): void {
    $export = AuditExport::query()->create([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'requested_by' => $this->owner->id, 'status' => 'queued',
        'from_date' => $this->window['from'], 'to_date' => $this->window['to'],
    ]);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'/'.$export->id.'/download')->assertNotFound();
});

test('a failing package build marks the export as failed without leaking the reason', function (): void {
    config(['audit_ledger.export.disk' => 'a_disk_that_does_not_exist']);

    $response = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->postJson($this->base, $this->window)->assertStatus(202);
    $export = AuditExport::query()->findOrFail($response->json('data.id'));

    expect($export->status)->toBe('failed')->and($export->error)->not->toBeNull();

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'/'.$export->id)
        ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'export_failed')->assertJsonPath('data.download_url', null);
});
