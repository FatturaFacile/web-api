<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Invoice;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use App\Services\Audit\AuditTrailRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = tfrTenant('Audit Api Tenant');
    config()->set('audit.worm.disk', 'audit_worm_test');
    config()->set('filesystems.disks.audit_worm_test', ['driver' => 'local', 'root' => storage_path('framework/testing/audit-api-'.Str::random(8)), 'throw' => true]);

    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Api Client', 'type' => 'customer', 'country' => 'IT']);
    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-API-1',
        'date' => now()->toDateString(),
        'total_net' => 100,
        'total_gross' => 122,
        'payment_status' => 'unpaid',
    ]);
});

function auditAs(object $user): object
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('a member without audit permissions gets 403 on every audit endpoint', function (): void {
    $member = tfrUserWithRole($this->tenant->id, 'member');
    $log = AuditLog::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

    $client = auditAs($member);
    $client->getJson('/api/v1/audit-logs')->assertForbidden();
    $client->getJson('/api/v1/audit-logs/'.$log->id)->assertForbidden();
    $client->getJson('/api/v1/audit-logs/entity/Invoice/'.$this->invoice->id)->assertForbidden();
    $client->postJson('/api/v1/audit-logs/verify-integrity')->assertForbidden();
    $client->postJson('/api/v1/audit-logs/worm-batches/seal')->assertForbidden();
    $client->getJson('/api/v1/audit-logs/export')->assertForbidden();
});

test('an auditor holds only the audit permissions the membership grants', function (): void {
    ['tenant' => $tenant] = ['tenant' => $this->tenant];
    $auditor = tfrUserWithRole($tenant->id, 'member');
    DB::table('tenant_user')->where('user_id', $auditor->id)->update(['permissions' => json_encode(['audit.view'])]);

    $client = auditAs($auditor);
    $client->getJson('/api/v1/audit-logs')->assertOk();
    $client->postJson('/api/v1/audit-logs/verify-integrity')->assertForbidden();
    $client->getJson('/api/v1/audit-logs/export')->assertForbidden();
});

test('the list is paginated, filterable and never carries values', function (): void {
    $this->invoice->update(['payment_status' => 'paid']);

    $response = auditAs($this->owner)->getJson('/api/v1/audit-logs?auditable_type=Invoice&event_type=UPDATED&changed_field=payment_status')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.event_type'))->toBe('UPDATED')
        ->and($response->json('data.0.changed_fields'))->toContain('payment_status')
        ->and($response->json('data.0'))->not->toHaveKeys(['old_values', 'new_values'])
        ->and($response->json('meta.total'))->toBe(1);

    auditAs($this->owner)->getJson('/api/v1/audit-logs?event_type[]=NOPE')->assertUnprocessable();
});

test('opening a record decrypts it and writes an AUDIT_LOG_INSPECTED meta record', function (): void {
    $this->invoice->update(['payment_status' => 'paid']);
    $log = AuditLog::query()->where('auditable_id', $this->invoice->id)->where('event_type', 'UPDATED')->firstOrFail();
    $before = AuditLog::query()->where('tenant_id', $this->tenant->id)->count();

    $response = auditAs($this->owner)->getJson('/api/v1/audit-logs/'.$log->id)->assertOk();

    expect($response->json('data.old_values.payment_status'))->toBe('unpaid')
        ->and($response->json('data.new_values.payment_status'))->toBe('paid')
        ->and($response->json('data.proof.current_hash'))->toBe($log->current_hash)
        ->and($response->json('data.proof.previous_hash'))->toBe($log->previous_hash)
        ->and($response->json('data.proof.worm_batch'))->toBeNull()
        ->and(AuditLog::query()->where('tenant_id', $this->tenant->id)->count())->toBe($before + 1);

    $meta = AuditLog::query()->where('tenant_id', $this->tenant->id)->orderByDesc('sequence_number')->firstOrFail();
    expect($meta->event_type)->toBe('AUDIT_LOG_INSPECTED')
        ->and($meta->auditable_id)->toBe($log->id)
        ->and($meta->actor_id)->toBe($this->owner->id);
});

test('a record of another tenant is not found', function (): void {
    ['tenant' => $other] = tfrTenant('Foreign Audit Tenant');
    $foreign = AuditLog::query()->where('tenant_id', $other->id)->first()
        ?? app(AuditTrailRecorder::class)->record(AuditEventType::Created, 'Thing', 'x', $other->id);

    auditAs($this->owner)->getJson('/api/v1/audit-logs/'.$foreign->id)->assertNotFound();
});

test('the entity timeline lists the whole history of a record in order', function (): void {
    $this->invoice->update(['payment_status' => 'paid']);
    $this->invoice->delete();
    $this->invoice->restore();

    $events = auditAs($this->owner)->getJson('/api/v1/audit-logs/entity/Invoice/'.$this->invoice->id)->assertOk()->json('data.*.event_type');

    expect($events)->toBe(['CREATED', 'UPDATED', 'DELETED', 'RESTORED']);
});

test('reading an invoice logs one VIEWED record, debounced per user, and a second user is logged at once', function (): void {
    $other = tfrUserWithRole($this->tenant->id, 'member');
    $url = '/api/v1/sales/invoices/'.$this->invoice->id;

    auditAs($this->owner)->withHeaders(['User-Agent' => 'AuditTest/1.0'])->getJson($url)->assertOk();
    auditAs($this->owner)->getJson($url)->assertOk();

    $views = fn () => AuditLog::query()->where('event_type', 'VIEWED')->where('auditable_id', $this->invoice->id)->orderBy('sequence_number')->get();

    expect($views())->toHaveCount(1)
        ->and($views()[0]->actor_id)->toBe($this->owner->id)
        ->and($views()[0]->actor_email)->toBe($this->owner->email)
        ->and($views()[0]->actor_role)->toBe('owner')
        ->and($views()[0]->request_id)->not->toBeNull()
        ->and($views()[0]->user_agent)->toBe('AuditTest/1.0')
        ->and($views()[0]->ip_address)->not->toBeNull();

    auditAs($other)->getJson($url)->assertOk();

    expect($views())->toHaveCount(2)->and($views()[1]->actor_id)->toBe($other->id);

    Cache::flush();
    auditAs($this->owner)->getJson($url)->assertOk();
    expect($views())->toHaveCount(3);
});

test('an export route records an EXPORTED event with its format', function (): void {
    Route::middleware(['auth:sanctum', 'tenant'])->get('/api/v1/_audit-probe/{id}', fn () => response('pdf-bytes'))->middleware('audit.access:Invoice,EXPORTED,pdf');

    auditAs($this->owner)->get('/api/v1/_audit-probe/'.$this->invoice->id.'?lang=it')->assertOk();

    $export = AuditLog::query()->where('event_type', 'EXPORTED')->where('auditable_id', $this->invoice->id)->firstOrFail();
    expect($export->context_metadata['format'])->toBe('pdf')
        ->and($export->context_metadata['query']['lang'])->toBe('it');
});

test('the verify endpoint returns a signed certificate and the list exposes the last verification', function (): void {
    $response = auditAs($this->owner)->postJson('/api/v1/audit-logs/verify-integrity')->assertOk();

    expect($response->json('data.isValid'))->toBeTrue()
        ->and($response->json('data.checkedRecords'))->toBe(AuditLog::query()->where('tenant_id', $this->tenant->id)->count())
        ->and($response->json('data.merkleRoot'))->toHaveLength(64)
        ->and($response->json('data.signature'))->toHaveLength(64);

    expect(auditAs($this->owner)->getJson('/api/v1/audit-logs')->json('meta.last_verification.isValid'))->toBeTrue();

    auditAs($this->owner)->postJson('/api/v1/audit-logs/verify-integrity', ['from_sequence' => 5, 'to_sequence' => 2])->assertUnprocessable();
});

test('the verify endpoint reports a tampered chain', function (): void {
    DB::unprepared('DROP TRIGGER prevent_audit_log_update');
    DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('sequence_number', 2)->update(['actor_email' => 'evil@example.com']);

    $response = auditAs($this->owner)->postJson('/api/v1/audit-logs/verify-integrity')->assertOk();

    expect($response->json('data.isValid'))->toBeFalse()
        ->and($response->json('data.brokenAtSequence'))->toBe(2);
});

test('WORM batches are sealed on demand, listed, and sealing again is a no-op', function (): void {
    $seal = auditAs($this->owner)->postJson('/api/v1/audit-logs/worm-batches/seal')->assertOk();
    expect($seal->json('data'))->toHaveCount(1);

    $list = auditAs($this->owner)->getJson('/api/v1/audit-logs/worm-batches')->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.merkle_root_hash'))->toHaveLength(64)
        ->and(Storage::disk('audit_worm_test')->exists($list->json('data.0.storage_path')))->toBeTrue();

    expect(auditAs($this->owner)->postJson('/api/v1/audit-logs/worm-batches/seal')->json('data'))->toBe([]);

    $log = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('sequence_number', 1)->firstOrFail();
    expect(auditAs($this->owner)->getJson('/api/v1/audit-logs/'.$log->id)->json('data.proof.worm_batch.id'))->toBe($list->json('data.0.id'));
});

test('the forensic export is a self-verifying JSONL bundle and is itself audited', function (): void {
    $response = auditAs($this->owner)->get('/api/v1/audit-logs/export?auditable_type=Invoice')->assertOk();
    $lines = array_values(array_filter(explode("\n", $response->getContent())));
    $manifest = json_decode((string) array_pop($lines), true)['_manifest'];

    expect($lines)->toHaveCount(1)
        ->and($manifest['record_count'])->toBe(1)
        ->and($manifest['records_sha256'])->toBe(hash('sha256', implode("\n", $lines)."\n"))
        ->and($response->headers->get('X-Audit-Bundle-SHA256'))->toBe($manifest['records_sha256'])
        ->and($response->headers->get('X-Audit-Bundle-Signature'))->toBe($manifest['signature']);

    expect(AuditLog::query()->where('event_type', 'EXPORTED')->where('auditable_type', 'AuditLog')->count())->toBe(1);
});

test('no route edits or deletes an audit record', function (): void {
    foreach (app('router')->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/audit-logs')) {
            expect(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']))->toBe([]);
        }
    }
});

test('login events are written to the trail', function (): void {
    event(new Login('sanctum', $this->owner, false));
    event(new Failed('sanctum', null, ['email' => 'ghost@example.com']));

    expect(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', 'LOGIN_SUCCESS')->count())->toBe(1);

    $failed = AuditLog::query()->whereNull('tenant_id')->where('event_type', 'LOGIN_FAILED')->firstOrFail();

    expect($failed->context_metadata['attempted_email'])->toBe('ghost@example.com')
        ->and(app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery(null))->isValid)->toBeTrue();
});

test('a change of the membership role is a PERMISSION_CHANGED event', function (): void {
    $member = tfrUserWithRole($this->tenant->id, 'member');
    $member->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);

    expect(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', 'PERMISSION_CHANGED')->count())->toBe(1);
});
