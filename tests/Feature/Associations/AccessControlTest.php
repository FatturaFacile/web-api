<?php

declare(strict_types=1);

use App\Services\Backup\TenantBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Services\AccessDeviceService;
use Modules\Associations\Services\CredentialService;
use Modules\Associations\Services\FeeService;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->area = AccessArea::factory()->create();
    $registered = app(AccessDeviceService::class)->register(['area_id' => $this->area->id, 'name' => 'Gate 1']);
    $this->device = $registered['device'];
    $this->secret = $registered['secret'];
});

afterEach(fn () => associationsTearDown());

/**
 * A person with an active membership.
 */
function activeMember(array $attributes = []): Member
{
    $member = Member::factory()->create($attributes);
    $service = app(MembershipService::class);
    $service->activate($service->request($member, AssociativeYear::factory()->create(), MembershipType::factory()->create()));

    return $member;
}

function qrOf(Member $member): string
{
    $credentials = app(CredentialService::class);

    return $credentials->qrPayload($credentials->currentQr($member));
}

function verify(object $test, string $credential, array $extra = []): TestResponse
{
    return $test->withHeaders(['X-Device-Key' => $test->device->device_key, 'X-Device-Secret' => $test->secret])
        ->postJson('/api/v1/association-access/verify', ['credential' => $credential, ...$extra]);
}

test('only a registered reader with its secret can verify, and only its hash is stored', function (): void {
    $member = activeMember();

    $this->postJson('/api/v1/association-access/verify', ['credential' => qrOf($member)])->assertUnauthorized();
    $this->withHeaders(['X-Device-Key' => $this->device->device_key, 'X-Device-Secret' => 'wrong'])->postJson('/api/v1/association-access/verify', ['credential' => qrOf($member)])->assertUnauthorized();

    expect($this->device->secret_hash)->toBe(hash('sha256', $this->secret))
        ->and($this->device->toArray())->not->toHaveKey('secret_hash');

    $this->device->update(['is_active' => false]);
    verify($this, qrOf($member))->assertUnauthorized();
});

test('a regular member gets in, the attempt is logged and the occupancy counts', function (): void {
    $member = activeMember();

    verify($this, qrOf($member))->assertOk()
        ->assertJsonPath('data.decision', 'allow')
        ->assertJsonPath('data.reason_code', 'granted')
        ->assertJsonPath('data.occupancy', 1);

    $log = AccessLog::query()->firstOrFail();

    expect($log->member_id)->toBe($member->id)
        ->and($log->decision)->toBe('allow')
        ->and($log->tenant_id)->toBe($this->tenant->id);

    verify($this, qrOf($member))->assertJsonPath('data.occupancy', 1);
    verify($this, qrOf($member), ['direction' => 'out'])->assertJsonPath('data.reason_code', 'exit_recorded')->assertJsonPath('data.occupancy', 0);
});

test('the answer of the reader carries no personal data', function (): void {
    $member = activeMember();

    $content = (string) verify($this, qrOf($member))->getContent();

    expect($content)->not->toContain($member->contact->last_name)->not->toContain($member->id)->not->toContain((string) $member->contact->email);
});

test('credentials are refused with their reason', function (): void {
    $member = activeMember();
    $credentials = app(CredentialService::class);
    $qr = qrOf($member);

    verify($this, 'NOT-A-CARD')->assertJsonPath('data.reason_code', 'unknown_credential')->assertJsonPath('data.decision', 'deny');
    verify($this, substr($qr, 0, -2).'zz')->assertJsonPath('data.reason_code', 'invalid_signature');

    $current = $credentials->currentQr($member);
    $current->update(['expires_at' => now()->subMinute()]);
    verify($this, $qr)->assertJsonPath('data.reason_code', 'credential_expired');

    $current->update(['expires_at' => null]);
    $credentials->revoke($current, 'lost');
    verify($this, $qr)->assertJsonPath('data.reason_code', 'credential_revoked');
});

test('a card of another tenant is unknown here', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => qrOf(activeMember()));

    verify($this, $foreign)->assertJsonPath('data.reason_code', 'unknown_credential');
});

test('a badge opens like a QR', function (): void {
    $member = activeMember();
    app(CredentialService::class)->registerBadge($member, 'BADGE-0001', null);

    verify($this, 'BADGE-0001')->assertJsonPath('data.decision', 'allow');
    expect(AccessLog::query()->firstOrFail()->credential_id)->not->toBeNull();
    expect(DB::table('assoc_access_logs')->get()->toJson())->not->toContain('BADGE-0001');
});

test('without a current membership access is denied', function (): void {
    $member = activeMember();
    $qr = qrOf($member);
    Membership::query()->where('member_id', $member->id)->update(['status' => MembershipStatus::Expired]);

    verify($this, $qr)->assertJsonPath('data.reason_code', 'membership_inactive');

    Membership::query()->where('member_id', $member->id)->delete();

    verify($this, $qr)->assertJsonPath('data.reason_code', 'membership_missing');
});

test('an overdue fee blocks where the area checks fees', function (): void {
    $member = activeMember();
    app(FeeService::class)->charge($member, 'Fee', '50.00', CarbonImmutable::yesterday());

    verify($this, qrOf($member))->assertJsonPath('data.reason_code', 'fee_overdue')->assertJsonPath('data.occupancy', 0);

    $this->area->update(['check_fee' => false]);

    verify($this, qrOf($member))->assertJsonPath('data.decision', 'allow');
});

test('a fee not yet due does not block', function (): void {
    $member = activeMember();
    app(FeeService::class)->charge($member, 'Fee', '50.00', CarbonImmutable::tomorrow());

    verify($this, qrOf($member))->assertJsonPath('data.decision', 'allow');
});

test('a missing or expired certificate blocks where required', function (): void {
    $this->area->update(['check_certificate' => true, 'required_certificate' => CertificateKind::Competitive]);
    $member = activeMember();

    verify($this, qrOf($member))->assertJsonPath('data.reason_code', 'certificate_missing');

    $certificate = MedicalCertificate::factory()->create(['member_id' => $member->id, 'kind' => CertificateKind::Competitive, 'expires_on' => now()->subDay()]);
    verify($this, qrOf($member))->assertJsonPath('data.reason_code', 'certificate_expired');

    $certificate->update(['expires_on' => now()->addMonth()]);
    verify($this, qrOf($member))->assertJsonPath('data.decision', 'allow');
});

test('an area with a course check lets in participants and instructors of the lesson of the moment', function (): void {
    $this->area->update(['check_course' => true, 'course_margin_minutes' => 15]);
    $this->travelTo(now()->startOfDay()->addHours(18));
    $course = Course::factory()->create(['access_area_id' => $this->area->id]);
    $course->schedules()->create(['pattern' => 'daily', 'starts_at' => '18:00', 'ends_at' => '19:00']);
    $participant = activeMember();
    $instructor = activeMember();
    $stranger = activeMember();
    CourseEnrolment::query()->create(['course_id' => $course->id, 'member_id' => $participant->id, 'enrolled_on' => now()]);
    CourseInstructor::query()->create(['course_id' => $course->id, 'member_id' => $instructor->id]);

    verify($this, qrOf($participant))->assertJsonPath('data.decision', 'allow');
    verify($this, qrOf($instructor))->assertJsonPath('data.decision', 'allow');
    verify($this, qrOf($stranger))->assertJsonPath('data.reason_code', 'no_course_now');

    $this->travelTo(now()->startOfDay()->addHours(21));
    verify($this, qrOf($participant))->assertJsonPath('data.reason_code', 'no_course_now');

    $this->travelTo(now()->startOfDay()->addHours(18));
    $course->suspensions()->create(['starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString()]);
    AccessLog::query();
    DB::table('assoc_area_presences')->update(['left_at' => now()]);
    verify($this, qrOf($participant))->assertJsonPath('data.reason_code', 'course_suspended');
});

test('a full area refuses, and whoever is already inside is not counted twice', function (): void {
    $this->area->update(['capacity' => 1]);
    [$first, $second] = [activeMember(), activeMember()];

    verify($this, qrOf($first))->assertJsonPath('data.decision', 'allow');
    verify($this, qrOf($second))->assertJsonPath('data.reason_code', 'area_full');
    verify($this, qrOf($first))->assertJsonPath('data.decision', 'allow');
    verify($this, qrOf($first), ['direction' => 'out'])->assertJsonPath('data.occupancy', 0);
    verify($this, qrOf($second))->assertJsonPath('data.decision', 'allow');
});

test('who left without passing a reader stops counting after the configured hours', function (): void {
    $this->area->update(['capacity' => 1]);
    [$first, $second] = [activeMember(), activeMember()];

    verify($this, qrOf($first))->assertJsonPath('data.decision', 'allow');
    $this->travel(13)->hours();
    verify($this, qrOf($second))->assertJsonPath('data.decision', 'allow');
});

test('areas are independent', function (): void {
    $pool = AccessArea::factory()->create(['capacity' => 1]);
    $registered = app(AccessDeviceService::class)->register(['area_id' => $pool->id, 'name' => 'Pool gate']);
    $member = activeMember();

    verify($this, qrOf($member))->assertJsonPath('data.occupancy', 1);

    $this->withHeaders(['X-Device-Key' => $registered['device']->device_key, 'X-Device-Secret' => $registered['secret']])
        ->postJson('/api/v1/association-access/verify', ['credential' => qrOf($member)])
        ->assertJsonPath('data.occupancy', 1)
        ->assertJsonPath('data.area', $pool->code);
});

test('the access log cannot be updated, deleted or truncated, not even with SQL', function (string $statement): void {
    verify($this, 'UNKNOWN');
    $id = AccessLog::query()->firstOrFail()->id;

    expect(fn () => DB::transaction(fn () => DB::statement(str_replace(':id', "'{$id}'", $statement))))->toThrow(QueryException::class, 'immutable');
})->with([
    'update' => ["UPDATE assoc_access_logs SET decision = 'allow' WHERE id = :id"],
    'delete' => ['DELETE FROM assoc_access_logs WHERE id = :id'],
    'truncate' => ['TRUNCATE assoc_access_logs'],
]);

test('the model refuses too', function (): void {
    verify($this, 'UNKNOWN');
    $log = AccessLog::query()->firstOrFail();

    expect(fn () => $log->update(['decision' => 'allow']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

test('the log is append-only for backups too, and nothing cascades into it', function (): void {
    $foreignKeys = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'assoc_access_logs'::regclass AND contype = 'f'");

    expect(TenantBackupService::APPEND_ONLY_TABLES)->toContain('assoc_access_logs')
        ->and($foreignKeys)->toBe([]);
});

test('staff manage readers, read the log of their own company and see the occupancy', function (): void {
    actAs($this, 'secretary');
    verify($this, 'UNKNOWN');
    inTenant($this->otherTenant, fn () => AccessLog::query()->create([
        'device_id' => $this->device->id, 'area_id' => $this->area->id, 'direction' => 'in', 'decision' => 'deny', 'reason_code' => 'unknown_credential', 'occurred_at' => now(),
    ]));

    actAs($this, 'secretary');

    expect($this->getJson('/api/v1/associations/access-logs')->assertOk()->json('data'))->toHaveCount(1);

    $created = $this->postJson('/api/v1/associations/access-devices', ['area_id' => $this->area->id, 'name' => 'Gate 2'])->assertCreated()->json('data');

    expect($created)->toHaveKey('secret')->not->toHaveKey('secret_hash')
        ->and(collect($this->getJson('/api/v1/associations/access-devices')->json('data'))->firstWhere('id', $created['id']))->not->toHaveKey('secret');

    $this->getJson("/api/v1/associations/access-areas/{$this->area->id}/occupancy")->assertOk()->assertJsonPath('data.occupancy', 0);
    $this->postJson("/api/v1/associations/access-devices/{$created['id']}/rotate-secret")->assertOk();
    $this->postJson("/api/v1/associations/access-devices/{$created['id']}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

    expect(AccessDevice::query()->findOrFail($created['id'])->is_active)->toBeFalse();
});

test('a fee assignment of a course does not bleed into the overall state of somebody else', function (): void {
    $member = activeMember();
    app(FeeService::class)->charge(activeMember(), 'Fee', '50.00', CarbonImmutable::yesterday());

    verify($this, qrOf($member))->assertJsonPath('data.decision', 'allow');
    expect(FeeAssignment::query()->count())->toBe(1);
});
