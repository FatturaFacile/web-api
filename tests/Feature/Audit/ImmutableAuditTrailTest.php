<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\AuditWormBatch;
use App\Models\Contact;
use App\Models\Invoice;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use App\Services\Audit\AuditChainCryptoService;
use App\Services\Audit\AuditTrailRecorder;
use App\Services\Audit\AuditWormArchiver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = tfrTenant('Audit Tenant');
    config()->set('audit.worm.disk', 'audit_worm_test');
    config()->set('filesystems.disks.audit_worm_test', ['driver' => 'local', 'root' => storage_path('framework/testing/audit-worm-'.Str::random(8)), 'throw' => true]);
});

function auditInvoice(string $tenantId): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenantId, 'company_name' => 'Audited Client', 'type' => 'customer', 'country' => 'IT']);

    return Invoice::create([
        'tenant_id' => $tenantId,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-AUDIT-1',
        'date' => now()->toDateString(),
        'total_net' => 100,
        'total_gross' => 122,
        'payment_status' => 'unpaid',
    ]);
}

/**
 * @return Collection<int, AuditLog>
 */
function auditEntriesOf(string $tenantId, string $type, string $id)
{
    return AuditLog::query()->where('tenant_id', $tenantId)->where('auditable_type', $type)->where('auditable_id', $id)->orderBy('sequence_number')->get();
}

test('an invoice lifecycle writes four chained records: created, updated, deleted, restored', function (): void {
    $invoice = auditInvoice($this->tenant->id);
    $invoice->update(['payment_status' => 'paid']);
    $invoice->delete();
    $invoice->restore();

    $entries = auditEntriesOf($this->tenant->id, 'Invoice', $invoice->id);

    expect($entries->pluck('event_type')->all())->toBe(['CREATED', 'UPDATED', 'DELETED', 'RESTORED'])
        ->and($entries->pluck('sequence_number')->all())->toBe(range($entries[0]->sequence_number, $entries[0]->sequence_number + 3))
        ->and($entries[1]->changed_fields)->toContain('payment_status');

    foreach ($entries->slice(1) as $index => $entry) {
        expect($entry->previous_hash)->toBe($entries[$index - 1]->current_hash);
    }

    $certificate = app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery($this->tenant->id));

    expect($certificate->isValid)->toBeTrue($certificate->failureReason ?? '')
        ->and($certificate->checkedRecords)->toBeGreaterThanOrEqual(5)
        ->and($certificate->merkleRoot)->toHaveLength(64);
});

test('the first record of a tenant links to the genesis hash and sequences start at one', function (): void {
    $first = app(AuditTrailRecorder::class)->record(AuditEventType::Created, 'Thing', 'a', $this->tenant->id);

    expect($first->sequence_number)->toBe(1)
        ->and($first->previous_hash)->toBe(str_repeat('0', 64));
});

test('old and new values are AES-256-GCM ciphertext at rest and masked fields stay masked after decryption', function (): void {
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Masked SA', 'type' => 'customer', 'country' => 'CH', 'iban' => 'CH9300762011623852957']);
    $contact->update(['iban' => 'CH5604835012345678009']);

    $raw = DB::table('audit_logs')->where('auditable_id', $contact->id)->where('event_type', 'UPDATED')->first();

    expect($raw->new_values_encrypted)->not->toContain('CH56')->not->toContain('{')
        ->and($raw->old_values_encrypted)->not->toContain('CH93');

    $crypto = app(AuditChainCryptoService::class);
    $new = $crypto->decrypt($raw->new_values_encrypted);
    $old = $crypto->decrypt($raw->old_values_encrypted);

    expect($new['iban'])->toBe('CH56 **** **** 8009')
        ->and($old['iban'])->toBe('CH93 **** **** 2957');
});

test('encrypted payloads fail authentication when altered', function (): void {
    $crypto = app(AuditChainCryptoService::class);
    $cipher = (string) $crypto->encrypt(['a' => 1]);
    $raw = base64_decode($cipher, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

    expect(fn () => $crypto->decrypt(base64_encode($raw)))->toThrow(RuntimeException::class);
});

test('the canonical json sorts keys recursively', function (): void {
    $crypto = app(AuditChainCryptoService::class);

    expect($crypto->canonicalJson(['b' => 1, 'a' => ['d' => 1, 'c' => [3, 1]]]))->toBe('{"a":{"c":[3,1],"d":1},"b":1}');
});

test('the database triggers refuse UPDATE and DELETE of an audit record', function (): void {
    auditInvoice($this->tenant->id);

    expect(fn () => DB::statement("UPDATE audit_logs SET actor_email = 'tampered@evil.com'"))
        ->toThrow(QueryException::class, 'Audit logs are strictly immutable');
    expect(fn () => DB::statement('DELETE FROM audit_logs'))
        ->toThrow(QueryException::class, 'Audit logs are strictly immutable');

    $log = AuditLog::query()->firstOrFail();
    expect(fn () => $log->forceFill(['actor_email' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

test('tampering with a record is detected and pinned to its sequence number', function (): void {
    $invoice = auditInvoice($this->tenant->id);
    $invoice->update(['payment_status' => 'paid']);
    $invoice->update(['payment_status' => 'unpaid']);

    $target = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('sequence_number', 3)->firstOrFail();
    $handler = app(VerifyAuditChainHandler::class);

    expect($handler->handle(new VerifyAuditChainQuery($this->tenant->id))->isValid)->toBeTrue();

    DB::unprepared('DROP TRIGGER prevent_audit_log_update');
    DB::table('audit_logs')->where('id', $target->id)->update(['changed_fields' => json_encode(['total_gross'])]);

    $certificate = $handler->handle(new VerifyAuditChainQuery($this->tenant->id));

    expect($certificate->isValid)->toBeFalse()
        ->and($certificate->brokenAtSequence)->toBe(3)
        ->and($certificate->failureReason)->toContain('altered');

    DB::table('audit_logs')->where('id', $target->id)->update(['changed_fields' => json_encode($target->changed_fields)]);
    DB::table('audit_logs')->where('id', $target->id)->update(['occurred_at' => '2001-01-01 00:00:00.000000']);

    expect($handler->handle(new VerifyAuditChainQuery($this->tenant->id))->brokenAtSequence)->toBe(3);
});

test('a deleted record is detected as a sequence gap', function (): void {
    $invoice = auditInvoice($this->tenant->id);
    $invoice->update(['payment_status' => 'paid']);
    $invoice->update(['payment_status' => 'unpaid']);

    DB::unprepared('DROP TRIGGER prevent_audit_log_delete');
    DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('sequence_number', 2)->delete();

    $certificate = app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery($this->tenant->id));

    expect($certificate->isValid)->toBeFalse()
        ->and($certificate->brokenAtSequence)->toBe(3)
        ->and($certificate->failureReason)->toContain('gap');
});

test('a forged signature is detected', function (): void {
    auditInvoice($this->tenant->id);

    DB::unprepared('DROP TRIGGER prevent_audit_log_update');
    DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('sequence_number', 1)->update(['hmac_signature' => str_repeat('a', 64)]);

    $certificate = app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery($this->tenant->id));

    expect($certificate->isValid)->toBeFalse()->and($certificate->failureReason)->toContain('hmac');
});

test('a range verification is anchored on the record before the range', function (): void {
    $invoice = auditInvoice($this->tenant->id);
    $invoice->update(['payment_status' => 'paid']);

    $handler = app(VerifyAuditChainHandler::class);
    $certificate = $handler->handle(new VerifyAuditChainQuery($this->tenant->id, 2, 3));

    expect($certificate->isValid)->toBeTrue()
        ->and($certificate->firstSequence)->toBe(2)
        ->and($certificate->checkedRecords)->toBe(2);
});

test('chains of two tenants are independent', function (): void {
    ['tenant' => $other] = tfrTenant('Other Audit Tenant');
    auditInvoice($this->tenant->id);
    $recorder = app(AuditTrailRecorder::class);

    expect($recorder->record(AuditEventType::Created, 'Thing', 'x', $other->id)->sequence_number)->toBeGreaterThanOrEqual(1)
        ->and(AuditLog::query()->where('tenant_id', $other->id)->min('sequence_number'))->toBe(1)
        ->and(app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery($other->id))->isValid)->toBeTrue();
});

test('WORM archival seals a batch with its manifest and is idempotent', function (): void {
    $invoice = auditInvoice($this->tenant->id);
    $invoice->update(['payment_status' => 'paid']);
    $before = AuditLog::query()->where('tenant_id', $this->tenant->id)->count();

    $this->artisan('audit:archive-worm', ['--tenant' => $this->tenant->id, '--verify' => true])->assertSuccessful();

    $batch = AuditWormBatch::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

    expect($batch->from_sequence)->toBe(1)
        ->and($batch->record_count)->toBe($before + 1)
        ->and($batch->to_sequence)->toBe($before + 1)
        ->and(Storage::disk($batch->storage_disk)->exists($batch->storage_path))->toBeTrue()
        ->and(Storage::disk($batch->storage_disk)->exists($batch->manifest_path))->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'WORM_SEALED')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(DB::table('audit_worm_batch_entries')->where('batch_id', $batch->id)->count())->toBe($before + 1)
        ->and(app(AuditWormArchiver::class)->verifyBatch($batch)['valid'])->toBeTrue();

    $this->artisan('audit:archive-worm', ['--tenant' => $this->tenant->id])->assertSuccessful();

    expect(AuditWormBatch::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    $invoice->update(['payment_status' => 'unpaid']);
    $this->artisan('audit:archive-worm', ['--tenant' => $this->tenant->id])->assertSuccessful();

    $second = AuditWormBatch::query()->where('tenant_id', $this->tenant->id)->orderByDesc('from_sequence')->firstOrFail();
    expect($second->from_sequence)->toBe($batch->to_sequence + 1);
});

test('a tampered archive file fails batch verification', function (): void {
    auditInvoice($this->tenant->id);
    $this->artisan('audit:archive-worm', ['--tenant' => $this->tenant->id])->assertSuccessful();

    $batch = AuditWormBatch::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $disk = Storage::disk($batch->storage_disk);
    @chmod($disk->path($batch->storage_path), 0644);
    $disk->put($batch->storage_path, $disk->get($batch->storage_path).' ');

    expect(app(AuditWormArchiver::class)->verifyBatch($batch)['valid'])->toBeFalse();
});
