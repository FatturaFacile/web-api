<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildFee;
use Modules\Childcare\Models\ReferencePerson;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\InterviewNote;
use Modules\HumanResources\Models\JobApplication;
use Modules\Visitors\Models\Visitor;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = PrivacyFixtures::tenant('Erasure AG');
    $this->admin = PrivacyFixtures::member($this->tenant);
    $this->headers = PrivacyFixtures::actAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Registers, verifies, starts and completes an erasure request for the subject; returns the completed request.
 *
 * @return array<string, mixed>
 */
function peErase(string $subjectType, string $subjectId, int $completeStatus = 200): array
{
    $base = '/api/v1/settings/privacy/data-subject-requests';
    $dsr = test()->withHeaders(test()->headers)->postJson($base, ['request_type' => 'erasure', 'subject_type' => $subjectType, 'subject_id' => $subjectId])->assertCreated()->json('data');
    test()->withHeaders(test()->headers)->postJson("{$base}/{$dsr['id']}/verify-identity", ['method' => 'id_document'])->assertOk();
    test()->withHeaders(test()->headers)->postJson("{$base}/{$dsr['id']}/start")->assertOk();

    return test()->withHeaders(test()->headers)->postJson("{$base}/{$dsr['id']}/complete")->assertStatus($completeStatus)->json('data') ?? [];
}

function peUser(Tenant $tenant, string $role = 'sales'): User
{
    return PrivacyFixtures::member($tenant, $role);
}

test('a user with a single membership and no employment is removed from the company and anonymised, everything audited', function (): void {
    $user = peUser($this->tenant);
    $email = $user->email;
    $user->createToken('device');

    $done = peErase('user', $user->id);

    $row = DB::table('users')->where('id', $user->id)->first();
    expect($done['status'])->toBe('completed')
        ->and($done['erasure_summary']['erased_fields'])->toContain('membership', 'profile', 'api_tokens')
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->count())->toBe(0)
        ->and($row->email)->not->toBe($email)
        ->and($row->email)->toEndWith('@redacted.invalid')
        ->and($row->name)->toBe('REDACTED')
        ->and((bool) $row->is_active)->toBeFalse()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'PRIVACY')->pluck('action')->all();
    expect($actions)->toContain('PRIVACY_USER_MEMBERSHIP_REMOVED', 'PRIVACY_USER_ANONYMISED', 'PRIVACY_ERASURE_EXECUTED');
    // the ledger never receives the e-mail address
    expect(json_encode(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->get()->toArray()))->not->toContain($email);
});

test('a user of several companies loses only the membership of the company and keeps the global profile', function (): void {
    $user = peUser($this->tenant);
    $other = PrivacyFixtures::tenant('Second AG');
    $user->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    $email = $user->email;

    $done = peErase('user', $user->id);

    expect($done['status'])->toBe('partially_completed_legal_hold')
        ->and($done['legal_holds'][0]['statute'])->toBe('Other company memberships')
        ->and($done['erasure_summary']['erased_fields'])->toContain('membership')->not->toContain('profile')
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->where('tenant_id', $other->id)->count())->toBe(1)
        ->and(DB::table('users')->where('id', $user->id)->value('email'))->toBe($email);
});

test('a user with employment records keeps the global profile and the records are reported as retained', function (): void {
    $user = peUser($this->tenant);
    app(TenantContext::class)->runWithTenant($this->tenant, fn () => Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]));
    $email = $user->email;

    $done = peErase('user', $user->id);

    $retained = collect($done['erasure_summary']['retained_records'])->keyBy('table');
    expect($done['status'])->toBe('partially_completed_legal_hold')
        ->and($retained->has('hr_employees'))->toBeTrue()
        ->and($retained['hr_employees']['retention'])->toBe('statutory_employment')
        ->and(DB::table('users')->where('id', $user->id)->value('email'))->toBe($email)
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->count())->toBe(0);
});

test('the last owner of a company cannot be erased and a user of another company is not a subject here', function (): void {
    // the administrator of the beforeEach is the only owner
    $base = '/api/v1/settings/privacy/data-subject-requests';
    $dsr = $this->withHeaders($this->headers)->postJson($base, ['request_type' => 'erasure', 'subject_type' => 'user', 'subject_id' => $this->admin->id])->assertCreated()->json('data');
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/verify-identity", ['method' => 'id_document'])->assertOk();
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/start")->assertOk();
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/complete")->assertUnprocessable();
    expect(DB::table('tenant_user')->where('user_id', $this->admin->id)->count())->toBe(1);

    $other = PrivacyFixtures::tenant('Foreign AG');
    $foreign = peUser($other);
    $this->withHeaders($this->headers)->postJson($base, ['request_type' => 'erasure', 'subject_type' => 'user', 'subject_id' => $foreign->id])->assertNotFound();
});

test('an association member is erased through the associations service: identity anonymised without holds, kept with them', function (): void {
    $free = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    $done = peErase('member', $free->id);
    expect($done['status'])->toBe('completed')
        ->and(Member::withoutGlobalScopes()->findOrFail($free->id)->erased_at)->not->toBeNull();

    $held = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    Membership::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $held->id]);
    $partial = peErase('member', $held->id);
    $retained = collect($partial['erasure_summary']['retained_records'])->pluck('table')->all();

    expect($partial['status'])->toBe('partially_completed_legal_hold')
        ->and($partial['legal_holds'][0]['statute'])->toContain('members_book')
        ->and($retained)->toContain('assoc_memberships')
        ->and(Member::withoutGlobalScopes()->findOrFail($held->id)->erased_at)->toBeNull();
});

test('a child is anonymised with its guardians; billing records keep the identity but never the health data', function (): void {
    $child = Child::factory()->create(['tenant_id' => $this->tenant->id, 'allergies' => 'peanuts', 'health_notes' => 'asthma']);
    ReferencePerson::factory()->create(['tenant_id' => $this->tenant->id, 'child_id' => $child->id, 'full_name' => 'Maria Rossi', 'phone' => '+41791112233', 'email' => 'maria@example.ch']);

    $done = peErase('child', $child->id);
    $row = Child::withoutGlobalScopes()->findOrFail($child->id);
    $guardian = ReferencePerson::withoutGlobalScopes()->where('child_id', $child->id)->firstOrFail();
    expect($done['status'])->toBe('completed')
        ->and($row->first_name)->toBe('REDACTED')
        ->and($row->allergies)->toBeNull()
        ->and($row->health_notes)->toBeNull()
        ->and($guardian->full_name)->toBe('REDACTED')
        ->and($guardian->phone)->toBeNull();

    $billed = Child::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Luca', 'health_notes' => 'asthma']);
    ChildFee::factory()->create(['tenant_id' => $this->tenant->id, 'child_id' => $billed->id]);
    $partial = peErase('child', $billed->id);
    $kept = Child::withoutGlobalScopes()->findOrFail($billed->id);

    expect($partial['status'])->toBe('partially_completed_legal_hold')
        ->and($kept->first_name)->toBe('Luca')
        ->and($kept->health_notes)->toBeNull()
        ->and(collect($partial['erasure_summary']['retained_records'])->pluck('table')->all())->toContain('cc_child_fees');
});

test('a visitor and a job applicant are anonymised, with the interview notes of the applicant', function (): void {
    $visitor = Visitor::factory()->create(['tenant_id' => $this->tenant->id]);
    $email = $visitor->email;
    expect(peErase('visitor', $visitor->id)['status'])->toBe('completed');
    $row = Visitor::withoutGlobalScopes()->findOrFail($visitor->id);
    expect($row->first_name)->toBe('REDACTED')->and($row->email)->not->toBe($email)->and($row->phone)->toBeNull();

    $application = JobApplication::factory()->create(['tenant_id' => $this->tenant->id]);
    InterviewNote::factory()->create(['tenant_id' => $this->tenant->id, 'job_application_id' => $application->id, 'note' => 'Candidate was nervous and talked about his divorce']);
    expect(peErase('applicant', $application->id)['status'])->toBe('completed');

    $app = JobApplication::withoutGlobalScopes()->findOrFail($application->id);
    expect($app->first_name)->toBe('REDACTED')
        ->and($app->email)->toEndWith('@redacted.invalid')
        ->and($app->cover_note)->toBeNull()
        ->and(InterviewNote::withoutGlobalScopes()->where('job_application_id', $application->id)->firstOrFail()->note)->toBeIn([null, 'REDACTED']);
});

test('vertical and user subjects of another company are not reachable, and restriction is refused where it cannot be applied', function (): void {
    $other = PrivacyFixtures::tenant('Another AG');
    $foreignVisitor = app(TenantContext::class)->runWithTenant($other, fn () => Visitor::factory()->create(['tenant_id' => $other->id]));
    $base = '/api/v1/settings/privacy/data-subject-requests';
    $this->withHeaders($this->headers)->postJson($base, ['request_type' => 'erasure', 'subject_type' => 'visitor', 'subject_id' => $foreignVisitor->id])->assertNotFound();

    $own = Visitor::factory()->create(['tenant_id' => $this->tenant->id]);
    $dsr = $this->withHeaders($this->headers)->postJson($base, ['request_type' => 'restriction', 'subject_type' => 'visitor', 'subject_id' => $own->id])->assertCreated()->json('data');
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/verify-identity", ['method' => 'id_document'])->assertOk();
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/start")->assertOk();
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/complete")->assertUnprocessable();
    expect(Visitor::withoutGlobalScopes()->findOrFail($own->id)->first_name)->toBe($own->first_name);
});

test('the export of a member, a child and a user covers their linked records and never leaks secrets', function (): void {
    $user = peUser($this->tenant);
    $base = '/api/v1/settings/privacy/data-subject-requests';
    $dsr = $this->withHeaders($this->headers)->postJson($base, ['request_type' => 'access', 'subject_type' => 'user', 'subject_id' => $user->id])->assertCreated()->json('data');
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/verify-identity", ['method' => 'id_document'])->assertOk();
    $this->withHeaders($this->headers)->postJson("{$base}/{$dsr['id']}/start")->assertOk();

    $path = $this->withHeaders($this->headers)->get("{$base}/{$dsr['id']}/export")->assertOk()->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $json = (string) $zip->getFromName('export.json');
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }
    $zip->close();

    expect($names)->toContain('users.csv', 'tenant_user.csv')
        ->and($json)->toContain($user->email)
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain('remember_token');
});

test('retention anonymises encrypted columns through their cast, stays readable and is idempotent', function (): void {
    $old = Visitor::factory()->create(['tenant_id' => $this->tenant->id]);
    Visitor::withoutGlobalScopes()->whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(200), 'last_visit_at' => null]);
    $recent = Visitor::factory()->create(['tenant_id' => $this->tenant->id]);
    $recentName = $recent->first_name;

    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/visitors', ['retention_days' => 90])->assertOk();

    $dry = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs')->assertOk()->json('data.0');
    expect($dry['matched_count'])->toBe(1)->and($dry['details']['sample_ids'])->toBe([$old->id]);

    $run = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs', ['dry_run' => false])->assertOk()->json('data.0');
    expect($run['processed_count'])->toBe(1);

    $anonymised = Visitor::withoutGlobalScopes()->findOrFail($old->id);
    expect($anonymised->first_name)->toBe('REDACTED')
        ->and($anonymised->email)->toEndWith('@redacted.invalid')
        ->and($anonymised->phone)->toBeNull()
        ->and(Visitor::withoutGlobalScopes()->findOrFail($recent->id)->first_name)->toBe($recentName);

    $again = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs', ['dry_run' => false])->assertOk()->json('data.0');
    expect($again['matched_count'])->toBe(0)->and($again['processed_count'])->toBe(0);
});
