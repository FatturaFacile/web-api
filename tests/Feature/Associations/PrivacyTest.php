<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Consent;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\ProcessingActivity;
use Modules\Associations\Services\LegalParameterService;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    config(['filesystems.private_disk' => 'private']);
});

afterEach(fn () => associationsTearDown());

function privacyActivityPayload(array $overrides = []): array
{
    return [
        'name' => 'Membership management',
        'data_subjects' => ['members', 'guardians of minors'],
        'data_categories' => ['identity data', 'contact data'],
        'purposes' => ['keeping the members book'],
        'legal_bases' => ['contract', 'legal_obligation'],
        'retention' => 'As long as the membership lasts, then as the law requires.',
        'recipients' => ['accountant'],
        ...$overrides,
    ];
}

test('processing activities are versioned: a final version is frozen and a new one supersedes it', function (): void {
    actAs($this, 'admin');
    $first = $this->postJson('/api/v1/associations/privacy/activities', privacyActivityPayload())->assertCreated()->json('data');
    $this->postJson("/api/v1/associations/privacy/activities/{$first['id']}/finalize")->assertOk()->assertJsonPath('data.status', 'final');
    $this->putJson("/api/v1/associations/privacy/activities/{$first['id']}", ['name' => 'Changed'])->assertConflict();

    $second = $this->postJson("/api/v1/associations/privacy/activities/{$first['id']}/versions")->assertCreated()->json('data');
    $this->postJson("/api/v1/associations/privacy/activities/{$first['id']}/versions")->assertConflict();
    $this->putJson("/api/v1/associations/privacy/activities/{$second['id']}", ['purposes' => ['keeping the members book', 'sending notices']])->assertOk();
    $this->postJson("/api/v1/associations/privacy/activities/{$second['id']}/finalize")->assertOk();

    expect($second['version'])->toBe(2)
        ->and($second['family_id'])->toBe($first['family_id'])
        ->and(ProcessingActivity::query()->findOrFail($first['id'])->status)->toBe('superseded');

    $register = $this->getJson('/api/v1/associations/privacy/register')->assertOk()
        ->assertJsonPath('meta.requires_professional_validation', true)->json('data');

    expect($register['rows'])->toHaveCount(1)
        ->and($register['rows'][0][1])->toBe('2')
        ->and($register['rows'][0][2])->toContain('sending notices')
        ->and($register['controller']['name'])->toBe('Polisportiva Alfa');

    expect((string) $this->get('/api/v1/associations/privacy/register/csv')->assertOk()->getContent())->toContain('Membership management');
    expect($this->get('/api/v1/associations/privacy/register/pdf')->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
});

test('the privacy notice is composed from the data of the company and flagged as a template', function (): void {
    actAs($this, 'admin');
    $this->postJson('/api/v1/associations/catalogs/privacy-subjects', ['role' => 'dpo', 'name' => 'Studio Rossi', 'email' => 'dpo@example.com'])->assertCreated();
    $activity = ProcessingActivity::factory()->consentBased()->create();

    $response = $this->getJson("/api/v1/associations/privacy/activities/{$activity->id}/notice")->assertOk()
        ->assertJsonPath('meta.template', true)
        ->assertJsonPath('meta.requires_professional_validation', true);
    $text = json_encode($response->json('data'));

    expect($text)->toContain('Polisportiva Alfa')->toContain('Studio Rossi')->toContain('sending the newsletter')
        ->and(collect($response->json('data.sections'))->pluck('heading'))->toContain(__('association_privacy.notice.withdrawal'));
    expect($this->get("/api/v1/associations/privacy/activities/{$activity->id}/notice/pdf")->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
});

test('appointments are printed for processors, DPOs and authorized persons only, and their identity is encrypted', function (): void {
    actAs($this, 'admin');
    $processor = $this->postJson('/api/v1/associations/catalogs/privacy-subjects', ['role' => 'processor', 'name' => 'Cloud Hosting srl', 'scope' => 'Hosting of the registry'])->assertCreated()->json('data.id');
    $controller = $this->postJson('/api/v1/associations/catalogs/privacy-subjects', ['role' => 'controller', 'name' => 'Polisportiva Alfa'])->json('data.id');

    $this->get("/api/v1/associations/privacy/subjects/{$processor}/appointment")->assertOk();
    $this->getJson("/api/v1/associations/privacy/subjects/{$controller}/appointment")->assertUnprocessable();

    expect(DB::table('assoc_privacy_subjects')->where('id', $processor)->value('name'))->not->toContain('Cloud Hosting');
});

test('a consent is recorded with its proof, only for an activity based on consent, and withdrawn once', function (): void {
    actAs($this, 'admin');
    $member = Member::factory()->create();
    $newsletter = ProcessingActivity::factory()->consentBased()->create();
    $contract = ProcessingActivity::factory()->create();

    $payload = ['member_id' => $member->id, 'activity_id' => $newsletter->id, 'purpose' => 'newsletter', 'method' => 'online_form', 'text_version' => 'v3', 'proof' => 'form submission 8f1c, IP 203.0.113.9'];
    $consent = $this->postJson('/api/v1/associations/privacy/consents', $payload)->assertCreated()->json('data');
    $this->postJson('/api/v1/associations/privacy/consents', [...$payload, 'activity_id' => $contract->id])->assertUnprocessable();

    expect($consent['proof_hash'])->toBe(hash('sha256', $payload['proof']))
        ->and(DB::table('assoc_consents')->where('id', $consent['id'])->value('proof'))->not->toContain('203.0.113.9');

    $this->postJson("/api/v1/associations/privacy/consents/{$consent['id']}/withdraw")->assertOk();
    $this->postJson("/api/v1/associations/privacy/consents/{$consent['id']}/withdraw")->assertConflict();
    expect(fn () => Consent::query()->findOrFail($consent['id'])->delete())->toThrow(LogicException::class);
});

test('requests of the data subjects are due in one month, can be extended once by two months, and show when overdue', function (): void {
    actAs($this, 'admin');
    $received = now()->subMonths(2)->startOfDay();
    $request = $this->postJson('/api/v1/associations/privacy/requests', ['kind' => 'access', 'requester_name' => 'Laura Neri', 'received_at' => $received->toIso8601String()])->assertCreated()->json('data');

    expect(substr($request['due_on'], 0, 10))->toBe($received->copy()->addMonthNoOverflow()->toDateString());
    $this->getJson('/api/v1/associations/privacy/requests?overdue=1')->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/associations/privacy/requests/{$request['id']}/extend", ['reason' => 'Many requests'])->assertOk()
        ->assertJsonPath('data.status', 'extended');
    $this->postJson("/api/v1/associations/privacy/requests/{$request['id']}/extend", ['reason' => 'Again'])->assertConflict();
    $this->getJson('/api/v1/associations/privacy/requests?overdue=1')->assertJsonCount(0, 'data');

    $this->postJson("/api/v1/associations/privacy/requests/{$request['id']}/complete", ['outcome' => 'Sent'])->assertUnprocessable();
    $this->postJson("/api/v1/associations/privacy/requests/{$request['id']}/reject", ['outcome' => 'Identity not proven'])->assertOk()->assertJsonPath('data.status', 'rejected');
});

test('access and portability export the data of the person as JSON, health data only for the health role', function (): void {
    actAs($this, 'admin');
    $member = Member::factory()->create(['health_notes' => 'asthma']);
    $id = $this->postJson('/api/v1/associations/privacy/requests', ['kind' => 'portability', 'member_id' => $member->id, 'requester_name' => 'X'])->json('data.id');

    $data = $this->getJson("/api/v1/associations/privacy/requests/{$id}/export")->assertOk()->json('data');

    expect($data['person']['tax_code'])->toBe($member->tax_code)->and($data['person']['health_notes'])->toBe('asthma');
});

test('erasure keeps what the members book needs while the retention period is not configured, and never breaks append-only tables', function (): void {
    $service = app(MembershipService::class);
    $member = Member::factory()->create(['health_notes' => 'diabetes', 'internal_notes' => 'late payer']);
    $service->activate($service->request($member, AssociativeYear::factory()->create(), MembershipType::factory()->create()));
    $document = MemberDocument::query()->create(['member_id' => $member->id, 'title' => 'ID', 'disk' => 'private', 'path' => 'tenants/x/doc.pdf', 'original_name' => 'id.pdf']);
    Storage::disk('private')->put('tenants/x/doc.pdf', 'pdf');
    $log = AccessLog::query()->create(['device_id' => (string) Str::uuid7(), 'area_id' => AccessArea::factory()->create()->id, 'member_id' => $member->id, 'direction' => 'in', 'decision' => 'allow', 'reason_code' => 'granted', 'occurred_at' => now()]);

    actAs($this, 'admin');
    $id = $this->postJson('/api/v1/associations/privacy/requests', ['kind' => 'erasure', 'member_id' => $member->id, 'requester_name' => 'Owner'])->json('data.id');
    $this->getJson("/api/v1/associations/privacy/requests/{$id}/erasure-preview")->assertOk()->assertJsonPath('data.kept.0.reason', 'retention_period_not_configured');

    $report = $this->postJson("/api/v1/associations/privacy/requests/{$id}/complete", ['outcome' => 'Erased within the legal limits'])->assertOk()->json('data.erasure_report');
    $member->refresh();

    expect($report['mode'])->toBe('partial')
        ->and($report['kept'][0]['record'])->toBe('members_book')
        ->and($member->health_notes)->toBeNull()
        ->and($member->internal_notes)->toBeNull()
        ->and($member->tax_code)->not->toBeNull()
        ->and($member->processing_restricted_at)->not->toBeNull()
        ->and(MemberDocument::withTrashed()->whereKey($document->id)->exists())->toBeFalse()
        ->and(DB::table('assoc_access_logs')->where('id', $log->id)->value('member_id'))->toBe($member->id);
    Storage::disk('private')->assertMissing('tenants/x/doc.pdf');
});

test('once the retention period configured has passed, erasure anonymizes the person', function (): void {
    $service = app(MembershipService::class);
    $member = Member::factory()->create(['left_on' => now()->subYears(4)->toDateString(), 'status' => MemberStatus::Ceased]);
    $service->activate($service->request($member, AssociativeYear::factory()->create(), MembershipType::factory()->create()));
    inTenant(null, static fn () => LegalParameter::query()->create(['code' => LegalParameterService::MembersBookRetentionYears, 'year' => (int) now()->year, 'value' => '99', 'source_reference' => 'generic row, must not win']));
    LegalParameter::query()->create(['code' => LegalParameterService::MembersBookRetentionYears, 'year' => (int) now()->year, 'value' => '2', 'source_reference' => 'company statute, test value']);
    $name = $member->contact->last_name;

    actAs($this, 'admin');
    $id = $this->postJson('/api/v1/associations/privacy/requests', ['kind' => 'erasure', 'member_id' => $member->id, 'requester_name' => 'Owner'])->json('data.id');
    $report = $this->postJson("/api/v1/associations/privacy/requests/{$id}/complete", ['outcome' => 'Erased'])->assertOk()->json('data.erasure_report');
    $member->refresh();

    expect($report['mode'])->toBe('full')
        ->and($member->tax_code)->toBeNull()
        ->and($member->getRawOriginal('tax_code_hash'))->toBeNull()
        ->and($member->erased_at)->not->toBeNull()
        ->and($member->contact->last_name)->not->toBe($name);
});

test('only owners and admins, or users with the privacy permission, reach the data protection records', function (): void {
    actAs($this, 'secretary');
    $this->getJson('/api/v1/associations/privacy/register')->assertForbidden();
    $this->getJson('/api/v1/associations/catalogs/privacy-subjects')->assertForbidden();

    actAs($this, 'accountant');
    $this->postJson('/api/v1/associations/privacy/requests', ['kind' => 'access', 'requester_name' => 'X'])->assertForbidden();

    actAs($this, 'secretary', ['associations.privacy']);
    $this->getJson('/api/v1/associations/privacy/register')->assertOk();
});

test('data protection records of another company stay invisible', function (): void {
    $foreign = inTenant($this->otherTenant, fn (): ProcessingActivity => ProcessingActivity::factory()->create());
    actAs($this, 'admin');

    $this->postJson("/api/v1/associations/privacy/activities/{$foreign->id}/finalize")->assertNotFound();
    $this->getJson('/api/v1/associations/privacy/activities')->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/associations/catalogs/data-assets', ['name' => 'Archive', 'kind' => 'physical', 'confidentiality' => 3, 'integrity' => 2, 'availability' => 5])->assertUnprocessable();
    $this->postJson('/api/v1/associations/catalogs/data-assets', ['name' => 'Archive', 'kind' => 'physical', 'confidentiality' => 3, 'integrity' => 2, 'availability' => 1])->assertCreated()->assertJsonPath('data.rating', 3);
});
