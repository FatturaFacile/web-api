<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\DTOs\BoardDecisionRequest;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Enums\DocumentMode;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Mail\AssociationNoticeMail;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentFormDocument;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Services\EnrolmentService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

function enrolmentForm(array $attributes = []): EnrolmentForm
{
    return EnrolmentForm::create([
        'public_token' => Str::random(48),
        'title' => 'Enrolment 2026',
        'year_id' => AssociativeYear::factory()->create()->id,
        'membership_type_id' => MembershipType::factory()->create()->id,
        ...$attributes,
    ]);
}

function applicant(array $overrides = []): array
{
    return [
        'first_name' => 'Giulia',
        'last_name' => 'Bianchi',
        'email' => 'giulia@example.com',
        'birth_date' => '1992-03-04',
        'tax_code' => 'BNCGLI92C44H501T',
        'privacy_accepted' => true,
        ...$overrides,
    ];
}

function enrolmentUrl(EnrolmentForm $form): string
{
    return "/api/v1/association-public/enrolment/{$form->public_token}";
}

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    Mail::fake();
    config(['filesystems.private_disk' => 'private']);
    $this->withoutMiddleware(ThrottleRequests::class);
});

afterEach(fn () => associationsTearDown());

function leavePublicContext(): void
{
    app(TenantContext::class)->clear();
}

test('the form is public and tells nothing about the tenant beyond its name', function (): void {
    $form = enrolmentForm(['fields' => [['code' => 'shirt', 'label' => 'Shirt', 'type' => 'text', 'required' => false]]]);
    leavePublicContext();

    $this->getJson(enrolmentUrl($form))->assertOk()
        ->assertJsonPath('data.association', 'Polisportiva Alfa')
        ->assertJsonPath('data.is_open', true)
        ->assertJsonMissingPath('data.tenant_id');
});

test('an automatic form admits the candidate, with card number and a personal link by mail', function (): void {
    RegistryType::factory()->create(['kind' => RegistryKind::Candidate]);
    $form = enrolmentForm();
    leavePublicContext();

    $response = $this->postJson(enrolmentUrl($form), applicant())->assertCreated();

    expect($response->json('data'))->toHaveKey('tracking_code')->not->toHaveKey('member_id');

    app(TenantContext::class)->setTenant($this->tenant);
    $application = EnrolmentApplication::query()->firstOrFail();

    expect($application->status)->toBe(ApplicationStatus::Approved)
        ->and($application->member->status)->toBe(MemberStatus::Active)
        ->and($application->membership->status)->toBe(MembershipStatus::Active)
        ->and($application->membership->card_number)->not->toBeNull()
        ->and($application->member->contact->email)->toBe('giulia@example.com')
        ->and($application->member->registryAssignments()->count())->toBe(1);

    Mail::assertSent(AssociationNoticeMail::class, fn (AssociationNoticeMail $mail): bool => $mail->hasTo('giulia@example.com') && str_contains((string) $mail->actionUrl, 'token='));
});

test('the tenant comes from the link: a tenant id sent by the client is ignored', function (): void {
    $form = enrolmentForm();
    leavePublicContext();

    $this->withHeader('X-Tenant-Id', $this->otherTenant->id)
        ->postJson(enrolmentUrl($form).'?tenant_id='.$this->otherTenant->id, applicant(['tenant_id' => $this->otherTenant->id]))
        ->assertCreated();

    expect(Member::withoutGlobalScopes()->firstOrFail()->tenant_id)->toBe($this->tenant->id)
        ->and(EnrolmentApplication::withoutGlobalScopes()->firstOrFail()->tenant_id)->toBe($this->tenant->id)
        ->and(Contact::withoutGlobalScopes()->where('email', 'giulia@example.com')->firstOrFail()->tenant_id)->toBe($this->tenant->id);
});

test('an unknown link, a deleted form and a closed form accept nothing', function (): void {
    $closed = enrolmentForm(['closes_at' => now()->subDay()]);
    $deleted = enrolmentForm();
    $deleted->delete();
    leavePublicContext();

    $this->getJson('/api/v1/association-public/enrolment/'.Str::random(48))->assertNotFound();
    $this->postJson(enrolmentUrl($deleted), applicant())->assertNotFound();
    $this->postJson(enrolmentUrl($closed), applicant())->assertConflict();

    expect(Member::withoutGlobalScopes()->count())->toBe(0);
});

test('a board form leaves the candidate waiting until the board decides', function (): void {
    $form = enrolmentForm(['approval_mode' => 'board']);
    leavePublicContext();

    $this->postJson(enrolmentUrl($form), applicant())->assertCreated();

    actAs($this, 'secretary');
    $application = EnrolmentApplication::query()->firstOrFail();

    expect($application->status)->toBe(ApplicationStatus::PendingBoard)
        ->and($application->member->status)->toBe(MemberStatus::Candidate)
        ->and($application->membership->status)->toBe(MembershipStatus::Pending)
        ->and($application->membership->card_number)->toBeNull();

    $this->postJson("/api/v1/associations/enrolment-applications/{$application->id}/approve", ['reference' => 'Board 12/2026'])->assertOk()->assertJsonPath('data.status', 'approved');

    expect($application->refresh()->decision_reference)->toBe('Board 12/2026')
        ->and($application->membership->refresh()->status)->toBe(MembershipStatus::Active);

    $this->postJson("/api/v1/associations/enrolment-applications/{$application->id}/reject")->assertConflict();
});

test('the board hook is a contract: an implementation receives the application and answers through the service', function (): void {
    $gateway = new class implements BoardDecisionGateway
    {
        public array $received = [];

        public function request(EnrolmentApplication $application): BoardDecisionRequest
        {
            $this->received[] = $application->id;

            return new BoardDecisionRequest('agenda-42');
        }
    };
    app()->instance(BoardDecisionGateway::class, $gateway);

    $form = enrolmentForm(['approval_mode' => 'board']);
    leavePublicContext();
    $this->postJson(enrolmentUrl($form), applicant())->assertCreated();

    app(TenantContext::class)->setTenant($this->tenant);
    $application = EnrolmentApplication::query()->firstOrFail();

    expect($gateway->received)->toBe([$application->id])
        ->and($application->decision_reference)->toBe('agenda-42');

    app(EnrolmentService::class)->applyBoardDecision($application, false, 'minutes-7', null, 'Not eligible');

    expect($application->refresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->membership->status)->toBe(MembershipStatus::Rejected)
        ->and($application->member->status)->toBe(MemberStatus::Inactive);
});

test('a membership type that needs the board overrides an automatic form', function (): void {
    $form = enrolmentForm(['membership_type_id' => MembershipType::factory()->create(['requires_board_decision' => true])->id]);
    leavePublicContext();

    $this->postJson(enrolmentUrl($form), applicant())->assertCreated();

    expect(EnrolmentApplication::withoutGlobalScopes()->firstOrFail()->status)->toBe(ApplicationStatus::PendingBoard);
});

test('required documents block the application, optional ones do not, download-only ones are never collected', function (): void {
    $form = enrolmentForm();
    $required = EnrolmentFormDocument::create(['form_id' => $form->id, 'title' => 'Statement', 'mode' => DocumentMode::Required]);
    EnrolmentFormDocument::create(['form_id' => $form->id, 'title' => 'Photo', 'mode' => DocumentMode::Optional]);
    $download = EnrolmentFormDocument::create(['form_id' => $form->id, 'title' => 'Statute', 'mode' => DocumentMode::DownloadOnly, 'disk' => 'private', 'path' => 'associations/statute.pdf', 'original_name' => 'statute.pdf']);
    Storage::disk('private')->put('associations/statute.pdf', '%PDF');
    leavePublicContext();

    $this->post(enrolmentUrl($form), applicant(), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors("documents.{$required->id}");

    $this->get(enrolmentUrl($form)."/documents/{$download->id}")->assertOk();

    $this->post(enrolmentUrl($form), applicant(['documents' => [
        $required->id => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'),
        $download->id => UploadedFile::fake()->create('statute.pdf', 20, 'application/pdf'),
    ]]), ['Accept' => 'application/json'])->assertCreated();

    $application = EnrolmentApplication::withoutGlobalScopes()->firstOrFail();

    expect(DB::table('assoc_enrolment_uploads')->where('application_id', $application->id)->count())->toBe(1)
        ->and(DB::table('assoc_enrolment_uploads')->value('path'))->toStartWith("tenants/{$this->tenant->id}/associations/enrolment/");
});

test('a file that is not a document is refused', function (): void {
    $form = enrolmentForm();
    $required = EnrolmentFormDocument::create(['form_id' => $form->id, 'title' => 'Statement', 'mode' => DocumentMode::Required]);
    leavePublicContext();

    $this->post(enrolmentUrl($form), applicant(['documents' => [$required->id => UploadedFile::fake()->create('virus.php', 5, 'text/x-php')]]), ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

test('configured fields are enforced and kept encrypted', function (): void {
    $form = enrolmentForm(['fields' => [['code' => 'level', 'label' => 'Level', 'type' => 'select', 'required' => true, 'options' => ['base', 'pro']]]]);
    leavePublicContext();

    $this->postJson(enrolmentUrl($form), applicant())->assertUnprocessable()->assertJsonValidationErrors('extra.level');
    $this->postJson(enrolmentUrl($form), applicant(['extra' => ['level' => 'other']]))->assertUnprocessable();
    $this->postJson(enrolmentUrl($form), applicant(['extra' => ['level' => 'pro']]))->assertCreated();

    $row = DB::table('assoc_enrolment_applications')->first();

    expect($row->payload)->not->toContain('Giulia')->not->toContain('BNCGLI')
        ->and(EnrolmentApplication::withoutGlobalScopes()->firstOrFail()->payload['extra']['level'])->toBe('pro');
});

test('a minor needs a guardian, who becomes the holder of the link', function (): void {
    $form = enrolmentForm();
    $birthDate = now()->subYears(12)->toDateString();
    leavePublicContext();

    $this->postJson(enrolmentUrl($form), applicant(['birth_date' => $birthDate]))->assertUnprocessable()->assertJsonValidationErrors('guardian_email');

    $this->postJson(enrolmentUrl($form), applicant(['birth_date' => $birthDate, 'guardian_first_name' => 'Marco', 'guardian_last_name' => 'Bianchi', 'guardian_email' => 'marco@example.com']))->assertCreated();

    app(TenantContext::class)->setTenant($this->tenant);
    $member = Member::query()->firstOrFail();

    expect($member->isMinor())->toBeTrue()
        ->and($member->guardianships()->firstOrFail()->guardian->email)->toBe('marco@example.com');

    Mail::assertSent(AssociationNoticeMail::class, fn (AssociationNoticeMail $mail): bool => $mail->hasTo('marco@example.com') && $mail->actionUrl !== null);
    Mail::assertNotSent(AssociationNoticeMail::class, fn (AssociationNoticeMail $mail): bool => $mail->hasTo('giulia@example.com'));
});

test('a public form never overwrites or admits a person already in the registry', function (): void {
    $form = enrolmentForm();
    $known = Member::factory()->create(['tax_code' => 'BNCGLI92C44H501T']);
    $originalEmail = $known->contact->email;
    leavePublicContext();

    $response = $this->postJson(enrolmentUrl($form), applicant(['email' => 'attacker@example.com', 'first_name' => 'Changed']))->assertCreated();

    expect($response->json('data'))->toHaveKeys(['tracking_code', 'received']);

    app(TenantContext::class)->setTenant($this->tenant);
    $application = EnrolmentApplication::query()->firstOrFail();

    expect($application->status)->toBe(ApplicationStatus::Submitted)
        ->and($application->member_id)->toBe($known->id)
        ->and($application->membership->status)->toBe(MembershipStatus::Pending)
        ->and($known->contact->refresh()->email)->toBe($originalEmail)
        ->and(Member::query()->count())->toBe(1);
});

test('the trap field stops bots', function (): void {
    $form = enrolmentForm();
    leavePublicContext();

    $this->postJson(enrolmentUrl($form), applicant(['website' => 'http://spam.example']))->assertUnprocessable();
});

test('an applicant follows the application with the tracking code alone', function (): void {
    $form = enrolmentForm(['approval_mode' => 'board']);
    leavePublicContext();

    $code = $this->postJson(enrolmentUrl($form), applicant())->json('data.tracking_code');

    $this->getJson(enrolmentUrl($form)."/applications/{$code}")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonMissingPath('data.member_id');
    $this->getJson(enrolmentUrl($form).'/applications/'.Str::random(40))->assertNotFound();
});

test('the public routes are throttled', function (): void {
    foreach (['api.v1.association-public.show', 'api.v1.association-public.submit', 'api.v1.association-public.documents', 'api.v1.association-access.verify', 'api.v1.association-payments.callback', 'api.v1.association-area.me'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect(collect($middleware)->contains(fn (string $entry): bool => str_starts_with($entry, 'throttle:')))->toBeTrue($name);
    }
});

test('too many submissions are refused', function (): void {
    $this->app->make('router')->getRoutes()->refreshNameLookups();
    $form = enrolmentForm();
    leavePublicContext();

    $this->withMiddleware(ThrottleRequests::class);
    config(['cache.default' => 'array']);

    $statuses = [];

    for ($attempt = 0; $attempt < 7; $attempt++) {
        $statuses[] = $this->postJson(enrolmentUrl($form), [])->status();
    }

    expect($statuses)->toContain(429);
});

test('a staff user of another tenant cannot read the applications', function (): void {
    $form = enrolmentForm();
    leavePublicContext();
    $this->postJson(enrolmentUrl($form), applicant())->assertCreated();

    actAs($this, 'secretary', tenant: $this->otherTenant);

    expect($this->getJson('/api/v1/associations/enrolment-applications')->assertOk()->json('data'))->toBe([]);
});
