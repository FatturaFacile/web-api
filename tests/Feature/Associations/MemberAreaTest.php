<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Portal\PortalTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Mail\AssociationNoticeMail;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Services\AreaTokenService;
use Modules\Associations\Services\FeePaymentService;
use Modules\Associations\Services\FeeService;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    AssociationSetting::current()->update(['ledger_enabled' => false]);
    $this->member = Member::factory()->create(['internal_notes' => 'Secret note']);
});

afterEach(fn () => associationsTearDown());

function area(object $test, Contact $contact): object
{
    return $test->withHeaders(areaHeaders(areaToken($contact)));
}

function paidFee(Member $member): FeeAssignment
{
    $assignment = app(FeeService::class)->charge($member, 'Annual fee', '40.00', CarbonImmutable::today());
    app(FeePaymentService::class)->record($assignment->instalments->first(), ['amount' => '40.00', 'paid_on' => now()->toDateString(), 'method' => 'cash']);

    return $assignment;
}

function guardianOf(Member $minor): Contact
{
    $guardian = Contact::create(['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com']);
    Guardianship::query()->create(['minor_member_id' => $minor->id, 'guardian_contact_id' => $guardian->id, 'is_primary' => true]);

    return $guardian;
}

test('the area needs a valid token', function (): void {
    $this->getJson('/api/v1/association-area/me')->assertUnauthorized();
    $this->withHeaders(areaHeaders('wrong'))->getJson('/api/v1/association-area/me')->assertNotFound();
});

test('a token that expired or was revoked no longer works', function (): void {
    $service = app(PortalTokenService::class);
    $expired = $service->createToken($this->member->contact, AreaTokenService::TokenType, expiresAt: now()->subDay());
    $revoked = $service->createToken($this->member->contact, AreaTokenService::TokenType);
    $revoked['model']->update(['revoked_at' => now()]);

    $this->withHeaders(areaHeaders($expired['token']))->getJson('/api/v1/association-area/me')->assertStatus(410);
    $this->withHeaders(areaHeaders($revoked['token']))->getJson('/api/v1/association-area/me')->assertStatus(410);
});

test('a token opens only its own area: never the customer portal, and the other way round', function (): void {
    $areaToken = areaToken($this->member->contact);
    $portalToken = app(PortalTokenService::class)->createToken($this->member->contact)['token'];

    $this->getJson("/api/v1/portal/access/{$areaToken}")->assertNotFound();
    $this->withHeaders(areaHeaders($portalToken))->getJson('/api/v1/association-area/me')->assertNotFound();
});

test('a member reads the own profile without the internal notes', function (): void {
    $response = area($this, $this->member->contact)->getJson('/api/v1/association-area/me')->assertOk();

    expect($response->json('data.roles'))->toBe(['member'])
        ->and($response->json('data.profile.id'))->toBe($this->member->id)
        ->and($response->getContent())->not->toContain('Secret note');
});

test('a member reads card, fees, receipts, calendar and shared documents', function (): void {
    $service = app(MembershipService::class);
    $service->activate($service->request($this->member, AssociativeYear::factory()->create(), MembershipType::factory()->create()));
    paidFee($this->member);
    $course = Course::factory()->create();
    $course->schedules()->create(['pattern' => 'daily', 'starts_at' => '18:00', 'ends_at' => '19:00']);
    CourseEnrolment::query()->create(['course_id' => $course->id, 'member_id' => $this->member->id, 'enrolled_on' => now()]);
    Storage::fake('private');
    Storage::disk('private')->put('associations/rules.pdf', '%PDF');
    $shared = MemberDocument::query()->create(['member_id' => $this->member->id, 'title' => 'Rules', 'disk' => 'private', 'path' => 'associations/rules.pdf', 'original_name' => 'rules.pdf', 'visible_to_member' => true]);
    $hidden = MemberDocument::query()->create(['member_id' => $this->member->id, 'title' => 'Internal', 'disk' => 'private', 'path' => 'associations/rules.pdf', 'original_name' => 'x.pdf']);

    $token = areaToken($this->member->contact);
    $api = $this->withHeaders(areaHeaders($token));
    $id = $this->member->id;

    expect($api->getJson("/api/v1/association-area/members/{$id}/card")->assertOk()->json('data.qr'))->toStartWith('AC1.')
        ->and($api->getJson("/api/v1/association-area/members/{$id}/fees")->assertOk()->json('data.color'))->toBe('green')
        ->and($api->getJson("/api/v1/association-area/members/{$id}/receipts")->assertOk()->json('data'))->toHaveCount(1)
        ->and($api->getJson("/api/v1/association-area/members/{$id}/calendar")->assertOk()->json('data'))->not->toBeEmpty()
        ->and($api->getJson("/api/v1/association-area/members/{$id}/documents")->assertOk()->json('data'))->toHaveCount(1);

    $api->get("/api/v1/association-area/members/{$id}/documents/{$shared->id}/download")->assertOk();
    $api->getJson("/api/v1/association-area/members/{$id}/documents/{$hidden->id}/download")->assertNotFound();

    expect(substr((string) $api->get("/api/v1/association-area/members/{$id}/card/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $receipt = Receipt::query()->firstOrFail();
    expect(substr((string) $api->get("/api/v1/association-area/receipts/{$receipt->id}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

test('nobody reaches another person', function (string $path): void {
    $other = Member::factory()->create();
    paidFee($other);
    $receipt = Receipt::query()->firstOrFail();

    area($this, $this->member->contact)->getJson(str_replace([':other', ':receipt'], [$other->id, $receipt->id], $path))->assertNotFound();
})->with([
    '/api/v1/association-area/members/:other/card',
    '/api/v1/association-area/members/:other/fees',
    '/api/v1/association-area/members/:other/receipts',
    '/api/v1/association-area/members/:other/calendar',
    '/api/v1/association-area/members/:other/documents',
    '/api/v1/association-area/receipts/:receipt/pdf',
]);

test('nobody reaches a person of another tenant, whatever the id', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => Member::factory()->create());

    area($this, $this->member->contact)->getJson("/api/v1/association-area/members/{$foreign->id}/fees")->assertNotFound();
});

test('a member updates only the own contact data', function (): void {
    $contact = $this->member->contact;

    area($this, $contact)->putJson('/api/v1/association-area/me/contact', [
        'email' => 'new@example.com',
        'phone' => '+39 333 000000',
        'first_name' => 'Hacked',
        'status' => 'ceased',
        'address' => ['street' => 'Via Roma', 'postal_code' => '24100', 'city' => 'Bergamo'],
    ])->assertOk();

    expect($contact->refresh()->email)->toBe('new@example.com')
        ->and($contact->first_name)->not->toBe('Hacked')
        ->and($contact->addresses()->firstOrFail()->city)->toBe('Bergamo')
        ->and($this->member->refresh()->status->value)->toBe('active');
});

test('an online payment starts only from an own instalment', function (): void {
    $own = app(FeeService::class)->charge($this->member, 'Fee', '40.00', CarbonImmutable::today())->instalments->first();
    $other = app(FeeService::class)->charge(Member::factory()->create(), 'Fee', '40.00', CarbonImmutable::today())->instalments->first();
    $api = area($this, $this->member->contact);

    $api->postJson("/api/v1/association-area/instalments/{$own->id}/pay")->assertCreated()->assertJsonPath('data.amount', '40.00');
    $api->postJson("/api/v1/association-area/instalments/{$other->id}/pay")->assertNotFound();
});

test('a guardian sees the minors in care, on a separate access, and nobody else', function (): void {
    $minor = Member::factory()->minor()->create();
    $otherMinor = Member::factory()->minor()->create();
    $guardian = guardianOf($minor);
    paidFee($minor);
    $api = area($this, $guardian);

    $me = $api->getJson('/api/v1/association-area/me')->assertOk();

    expect($me->json('data.roles'))->toBe(['guardian'])
        ->and($me->json('data.profile'))->toBeNull()
        ->and($me->json('data.wards.0.id'))->toBe($minor->id);

    $api->getJson("/api/v1/association-area/members/{$minor->id}/receipts")->assertOk()->assertJsonCount(1, 'data');
    $api->getJson("/api/v1/association-area/members/{$otherMinor->id}/fees")->assertNotFound();
    $api->putJson("/api/v1/association-area/members/{$minor->id}/contact", ['phone' => '+39 333 111111'])->assertOk();

    expect($minor->contact->refresh()->phone)->toBe('+39 333 111111');
});

test('a guardian whose link has ended loses the access', function (): void {
    $minor = Member::factory()->minor()->create();
    $guardian = guardianOf($minor);
    Guardianship::query()->update(['valid_to' => now()->subDay()->toDateString()]);

    area($this, $guardian)->getJson("/api/v1/association-area/members/{$minor->id}/fees")->assertNotFound();
});

test('a plain member cannot act as guardian', function (): void {
    $api = area($this, $this->member->contact);

    $api->putJson("/api/v1/association-area/members/{$this->member->id}/contact", ['phone' => '123'])->assertNotFound();
    $api->getJson("/api/v1/association-area/members/{$this->member->id}/authorizations")->assertNotFound();
});

test('the staff sends a personal link, which works, and revokes it', function (): void {
    actAs($this, 'secretary');

    $this->postJson("/api/v1/associations/contacts/{$this->member->contact_id}/area-link")->assertStatus(202)->assertJsonMissingPath('token');

    $link = null;
    Mail::assertSent(AssociationNoticeMail::class, function (AssociationNoticeMail $mail) use (&$link): bool {
        $link = $mail->actionUrl;

        return true;
    });

    parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

    $this->withHeaders(areaHeaders($query['token']))->getJson('/api/v1/association-area/me')->assertOk();

    actAs($this, 'secretary');
    $this->deleteJson("/api/v1/associations/contacts/{$this->member->contact_id}/area-link")->assertNoContent();

    $this->withHeaders(areaHeaders($query['token']))->getJson('/api/v1/association-area/me')->assertStatus(410);
});

test('a minor gets no link: the area is for the guardian', function (): void {
    actAs($this, 'secretary');
    $minor = Member::factory()->minor()->create();

    $this->postJson("/api/v1/associations/contacts/{$minor->contact_id}/area-link")->assertUnprocessable();
});

test('a contact that is nobody in the registry gets no link', function (): void {
    actAs($this, 'secretary');
    $contact = Contact::create(['first_name' => 'Nobody', 'email' => 'nobody@example.com']);

    $this->postJson("/api/v1/associations/contacts/{$contact->id}/area-link")->assertUnprocessable();
});

describe('instructors', function (): void {
    beforeEach(function (): void {
        $this->instructor = Member::factory()->create();
        $this->ownCourse = Course::factory()->create(['title' => 'Own course']);
        $this->ownCourse->schedules()->create(['pattern' => 'daily', 'starts_at' => '18:00', 'ends_at' => '19:00']);
        $this->otherCourse = Course::factory()->create(['title' => 'Other course']);
        $this->otherCourse->schedules()->create(['pattern' => 'daily', 'starts_at' => '18:00', 'ends_at' => '19:00']);
        CourseInstructor::query()->create(['course_id' => $this->ownCourse->id, 'member_id' => $this->instructor->id]);
        CourseEnrolment::query()->create(['course_id' => $this->ownCourse->id, 'member_id' => $this->member->id, 'enrolled_on' => now()]);
        CourseEnrolment::query()->create(['course_id' => $this->otherCourse->id, 'member_id' => $this->member->id, 'enrolled_on' => now()]);
    });

    test('an instructor sees only the own courses and their participants, without fee data', function (): void {
        paidFee($this->member);
        $api = area($this, $this->instructor->contact);

        expect($api->getJson('/api/v1/association-area/me')->json('data.roles'))->toContain('instructor')
            ->and(collect($api->getJson('/api/v1/association-area/instructor/courses')->assertOk()->json('data'))->pluck('title')->all())->toBe(['Own course']);

        $participants = $api->getJson("/api/v1/association-area/instructor/courses/{$this->ownCourse->id}/participants")->assertOk();

        expect($participants->json('data'))->toHaveCount(1)
            ->and(array_keys($participants->json('data.0')))->toBe(['member_id', 'name']);

        $api->getJson("/api/v1/association-area/instructor/courses/{$this->otherCourse->id}/participants")->assertNotFound();
    });

    test('an instructor records attendance on the own courses only', function (): void {
        $api = area($this, $this->instructor->contact);
        $entries = ['date' => now()->toDateString(), 'entries' => [['member_id' => $this->member->id, 'status' => 'present']]];

        $api->postJson("/api/v1/association-area/instructor/courses/{$this->ownCourse->id}/attendance", $entries)->assertOk()->assertJsonMissingPath('data.0.package_exhausted');
        $api->postJson("/api/v1/association-area/instructor/courses/{$this->otherCourse->id}/attendance", $entries)->assertNotFound();

        expect(Attendance::query()->count())->toBe(1)
            ->and(Attendance::query()->firstOrFail()->recorded_by_member_id)->toBe($this->instructor->id);
    });

    test('an instructor has no access to the money or the health of the participants', function (): void {
        paidFee($this->member);
        $api = area($this, $this->instructor->contact);

        $api->getJson("/api/v1/association-area/members/{$this->member->id}/fees")->assertNotFound();
        $api->getJson("/api/v1/association-area/members/{$this->member->id}/receipts")->assertNotFound();
        $api->getJson('/api/v1/association-area/receipts/'.Receipt::query()->firstOrFail()->id.'/pdf')->assertNotFound();
    });

    test('an assignment that has ended gives no access', function (): void {
        CourseInstructor::query()->update(['valid_to' => now()->subDay()->toDateString()]);

        area($this, $this->instructor->contact)->getJson("/api/v1/association-area/instructor/courses/{$this->ownCourse->id}/participants")->assertNotFound();
    });

    test('an open instalment of a participant is out of reach', function (): void {
        $instalment = app(FeeService::class)->charge($this->member, 'Fee', '10.00', CarbonImmutable::today())->instalments->first();

        area($this, $this->instructor->contact)->postJson("/api/v1/association-area/instalments/{$instalment->id}/pay")->assertNotFound();

        expect($instalment->refresh()->status)->toBe(InstalmentStatus::Open);
    });
});
