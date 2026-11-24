<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Contracts\OcrEngine;
use Modules\Documents\DTOs\OcrResult;
use Modules\Documents\Exceptions\OcrFailedException;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Models\DmsOcrJob;
use Modules\Documents\Models\DmsPreservationChainLink;
use Modules\Documents\Services\DocumentOcrPipeline;
use Modules\Documents\Services\OcrEngineRegistry;
use Modules\Documents\Services\PreservationChainService;
use Modules\DocumentTrust\Models\PreservationPackage;

uses(RefreshDatabase::class);

function dmwfMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function dmwfActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function dmwfUpload(string $name = 'contract.txt', string $content = 'Payment within 30 days'): string
{
    return test()->post('/api/v1/dms/documents', [
        'title' => 'Contract '.Str::random(4),
        'file' => UploadedFile::fake()->createWithContent($name, $content),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

function dmwfClosedPackage(Tenant $tenant, string $name, string $closedAt): PreservationPackage
{
    return PreservationPackage::create([
        'tenant_id' => $tenant->id,
        'document_class' => 'issued_invoice',
        'name' => $name,
        'status' => PreservationPackage::Closed,
        'package_hash' => hash('sha256', $name.Str::random(8)),
        'closed_at' => $closedAt,
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->admin = dmwfMember($this->tenant, 'admin');
    $this->member = dmwfMember($this->tenant, 'member');
    $this->accountant = dmwfMember($this->tenant, 'accountant');
    dmwfActAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a check-out lock with an expiry lapses and anybody can take the document', function (): void {
    $id = dmwfUpload();

    $this->postJson("/api/v1/dms/documents/{$id}/check-out", ['expires_in_minutes' => 30])
        ->assertOk()
        ->assertJsonPath('data.is_checked_out', true)
        ->assertJsonPath('data.lock_expires_at', fn ($value) => $value !== null);

    dmwfActAs($this->member, $this->tenant);
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertConflict();

    $this->travel(31)->minutes();

    $this->postJson("/api/v1/dms/documents/{$id}/check-out", ['expires_in_minutes' => 60])
        ->assertOk()
        ->assertJsonPath('data.checked_out_by', $this->member->id);

    $this->postJson("/api/v1/dms/documents/{$id}/check-out", ['expires_in_minutes' => 4])->assertUnprocessable();
});

test('only managers create approval templates and every step needs an approver', function (): void {
    $payload = ['name' => 'Two-step', 'steps' => [['name' => 'Finance', 'approver_type' => 'role', 'approver_role' => 'accountant']]];

    dmwfActAs($this->member, $this->tenant);
    $this->postJson('/api/v1/dms/approval-templates', $payload)->assertForbidden();

    dmwfActAs($this->admin, $this->tenant);
    $this->postJson('/api/v1/dms/approval-templates', ['name' => 'No approver', 'steps' => [['name' => 'x', 'approver_type' => 'user']]])->assertUnprocessable();
    $this->postJson('/api/v1/dms/approval-templates', [...$payload, 'steps' => [['name' => 'x', 'approver_type' => 'user', 'approver_user_id' => (string) Str::uuid()]]])->assertUnprocessable();
    $this->postJson('/api/v1/dms/approval-templates', $payload)
        ->assertCreated()
        ->assertJsonPath('data.steps.0.position', 1)
        ->assertJsonPath('data.steps.0.approver_role', 'accountant');
});

test('a document walks the steps of its template and the last approval completes it', function (): void {
    $template = $this->postJson('/api/v1/dms/approval-templates', ['name' => 'Contract', 'steps' => [
        ['name' => 'Finance', 'approver_type' => 'role', 'approver_role' => 'accountant'],
        ['name' => 'Director', 'approver_type' => 'user', 'approver_user_id' => $this->admin->id],
    ]])->assertCreated()->json('data.id');
    $id = dmwfUpload();

    dmwfActAs($this->member, $this->tenant);
    $request = $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $template, 'note' => 'Please review'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_step_detail.name', 'Finance')
        ->json('data.id');

    // One pending request per document, and the requester never decides.
    $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $template])->assertConflict();
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")->assertForbidden();

    // The route cannot change under a pending request.
    dmwfActAs($this->admin, $this->tenant);
    $this->putJson("/api/v1/dms/approval-templates/{$template}", ['steps' => [['name' => 'Other', 'approver_type' => 'role', 'approver_role' => 'admin']]])->assertConflict();
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")->assertForbidden();
    $this->getJson('/api/v1/dms/approval-requests?awaiting_me=1')->assertOk()->assertJsonCount(0, 'data');

    dmwfActAs($this->accountant, $this->tenant);
    $this->getJson('/api/v1/dms/approval-requests?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve", ['comment' => 'Numbers fine'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_step', 2);
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")->assertForbidden();

    dmwfActAs($this->admin, $this->tenant);
    $this->getJson('/api/v1/dms/approval-requests?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonCount(2, 'data.decisions');
    $this->postJson("/api/v1/dms/approval-requests/{$request}/approve")->assertConflict();
    $this->getJson("/api/v1/dms/approval-requests/{$request}")->assertOk()->assertJsonPath('data.decisions.0.comment', 'Numbers fine');
});

test('a rejection needs a reason and ends the request, and a pending request can be cancelled', function (): void {
    $template = $this->postJson('/api/v1/dms/approval-templates', ['name' => 'Single', 'steps' => [['name' => 'Finance', 'approver_type' => 'role', 'approver_role' => 'accountant']]])->json('data.id');
    $id = dmwfUpload();
    $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $template])->assertCreated();
    $request = DB::table('dms_approval_requests')->value('id');

    dmwfActAs($this->accountant, $this->tenant);
    $this->postJson("/api/v1/dms/approval-requests/{$request}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/dms/approval-requests/{$request}/reject", ['comment' => 'Wrong amount'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/dms/approval-requests/{$request}/cancel")->assertConflict();

    // A new request can start after the rejection; a stranger cannot cancel it, the requester can.
    dmwfActAs($this->admin, $this->tenant);
    $second = $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $template])->assertCreated()->json('data.id');
    dmwfActAs($this->member, $this->tenant);
    $this->postJson("/api/v1/dms/approval-requests/{$second}/cancel")->assertForbidden();
    dmwfActAs($this->admin, $this->tenant);
    $this->postJson("/api/v1/dms/approval-requests/{$second}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->deleteJson("/api/v1/dms/approval-templates/{$template}")->assertNoContent();
    expect(DmsApprovalTemplate::query()->count())->toBe(0);
});

test('an inactive template or one without steps cannot start a request', function (): void {
    $inactive = $this->postJson('/api/v1/dms/approval-templates', ['name' => 'Off', 'is_active' => false, 'steps' => [['name' => 'A', 'approver_type' => 'role', 'approver_role' => 'admin']]])->json('data.id');
    $empty = DmsApprovalTemplate::factory()->create(['tenant_id' => $this->tenant->id]);
    $id = dmwfUpload();

    $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $inactive])->assertConflict();
    $this->postJson("/api/v1/dms/documents/{$id}/approval-requests", ['template_id' => $empty->id])->assertConflict();
    $this->getJson('/api/v1/dms/approval-templates?active=1')->assertOk()->assertJsonCount(1, 'data');
});

test('the OCR pipeline reads a text version with the built-in engine', function (): void {
    $id = dmwfUpload('notes.txt', 'Invoice 42 due on Friday');

    $job = $this->postJson("/api/v1/dms/documents/{$id}/ocr")->assertStatus(202)->json('data.id');
    app(DocumentOcrPipeline::class)->process(DmsOcrJob::query()->findOrFail($job));

    $this->getJson("/api/v1/dms/ocr-jobs/{$job}?include_text=1")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.engine', 'plain_text')
        ->assertJsonPath('data.text_content', 'Invoice 42 due on Friday');
    $this->getJson("/api/v1/dms/documents/{$id}/ocr")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.text_content');
    $this->postJson("/api/v1/dms/ocr-jobs/{$job}/retry")->assertConflict();
});

test('a format without an engine fails with a reason and can be retried', function (): void {
    $id = dmwfUpload('scan.png', 'not really an image');

    $job = $this->postJson("/api/v1/dms/documents/{$id}/ocr")->assertStatus(202)->json('data.id');
    app(DocumentOcrPipeline::class)->process(DmsOcrJob::query()->findOrFail($job));

    $this->getJson("/api/v1/dms/ocr-jobs/{$job}")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', fn ($reason) => $reason !== null);
    // The sync queue of the tests runs the retry at once and the engine is still missing.
    $this->postJson("/api/v1/dms/ocr-jobs/{$job}/retry")->assertStatus(202);
    $this->getJson("/api/v1/dms/ocr-jobs/{$job}")->assertJsonPath('data.attempts', 2)->assertJsonPath('data.status', 'failed');
});

test('the registry hands the file to the first engine that supports it', function (): void {
    $engine = new class implements OcrEngine
    {
        public function name(): string
        {
            return 'fake_scanner';
        }

        public function supports(string $mimeType): bool
        {
            return $mimeType === 'image/png';
        }

        public function recognize(string $contents, string $mimeType): OcrResult
        {
            return new OcrResult('scanned text', 2, 91.5);
        }
    };
    $registry = new OcrEngineRegistry([...app(OcrEngineRegistry::class)->names() === [] ? [] : [], $engine]);

    expect($registry->engineFor('image/png')->name())->toBe('fake_scanner')
        ->and(fn () => $registry->engineFor('text/plain'))->toThrow(OcrFailedException::class);
    expect(app(OcrEngineRegistry::class)->names())->toBe(['plain_text']);
});

test('closed preservation packages are chained in closing order and the chain verifies', function (): void {
    $second = dmwfClosedPackage($this->tenant, 'Second', '2027-02-01 10:00:00');
    $first = dmwfClosedPackage($this->tenant, 'First', '2027-01-01 10:00:00');
    PreservationPackage::create(['tenant_id' => $this->tenant->id, 'document_class' => 'issued_invoice', 'name' => 'Still open']);

    dmwfActAs($this->member, $this->tenant);
    $this->postJson('/api/v1/dms/preservation-chain/anchor')->assertForbidden();

    dmwfActAs($this->admin, $this->tenant);
    $this->getJson('/api/v1/dms/preservation-chain/status')->assertOk()->assertJsonPath('data.unchained_packages', 2)->assertJsonPath('data.packages.0.sealing_status', 'awaiting_anchor');
    $this->postJson('/api/v1/dms/preservation-chain/anchor')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.package_id', $first->id)
        ->assertJsonPath('data.0.previous_link_hash', PreservationChainService::GenesisHash)
        ->assertJsonPath('data.1.package_id', $second->id);
    $this->postJson('/api/v1/dms/preservation-chain/anchor')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/dms/preservation-chain/verify')->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.links_checked', 2);
    $this->getJson('/api/v1/dms/preservation-chain/status')->assertJsonPath('data.chained_packages', 2)->assertJsonPath('data.packages.1.sealing_status', 'sealed');

    $third = dmwfClosedPackage($this->tenant, 'Third', '2027-03-01 10:00:00');
    $this->postJson('/api/v1/dms/preservation-chain/anchor')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sequence', 3);
    expect($third->id)->not->toBeEmpty();
});

test('altering a package or a link breaks the chain and links are append-only', function (): void {
    $first = dmwfClosedPackage($this->tenant, 'First', '2027-01-01 10:00:00');
    dmwfClosedPackage($this->tenant, 'Second', '2027-02-01 10:00:00');
    $this->postJson('/api/v1/dms/preservation-chain/anchor')->assertOk();

    $link = DmsPreservationChainLink::query()->where('sequence', 1)->firstOrFail();
    expect(fn () => $link->update(['link_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $link->delete())->toThrow(LogicException::class);

    DB::table('preservation_packages')->where('id', $first->id)->update(['package_hash' => str_repeat('b', 64)]);
    $this->getJson('/api/v1/dms/preservation-chain/verify')
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.broken_at_sequence', 1)
        ->assertJsonPath('data.reason', 'package_hash_mismatch');

    DB::table('preservation_packages')->where('id', $first->id)->update(['package_hash' => $link->package_hash]);
    DB::table('dms_preservation_chain_links')->where('sequence', 2)->update(['previous_link_hash' => str_repeat('c', 64)]);
    $this->getJson('/api/v1/dms/preservation-chain/verify')->assertJsonPath('data.broken_at_sequence', 2)->assertJsonPath('data.reason', 'previous_hash_mismatch');
});

test('the chain of a company never shows the packages of another', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    dmwfClosedPackage($other, 'Foreign', '2027-01-01 10:00:00');
    dmwfClosedPackage($this->tenant, 'Own', '2027-01-02 10:00:00');

    $this->postJson('/api/v1/dms/preservation-chain/anchor')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/dms/preservation-chain/status')->assertJsonPath('data.closed_packages', 1);
});
