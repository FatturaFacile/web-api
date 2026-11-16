<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\HumanResources\Events\CandidateApplicationReceivedEvent;
use Modules\HumanResources\Mail\CandidateStageMail;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;

use function Tests\Feature\HumanResources\Recruiting\careersSettings;
use function Tests\Feature\HumanResources\Recruiting\pdfCv;
use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\recruiterOfNewTenant;
use function Tests\Feature\HumanResources\Recruiting\sampleCvLines;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
});

/**
 * @return array<string, mixed>
 */
function publicApplicationPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Giulia',
        'last_name' => 'Verdi',
        'email' => 'Giulia.Verdi@example.com',
        'phone' => '+41 91 123 45 67',
        'locale' => 'it-CH',
        'consent_accepted' => '1',
        'consent_text_version' => 'v1',
        'website' => '',
        'cv' => pdfCv(sampleCvLines()),
        ...$overrides,
    ];
}

test('public application stores the CV privately, parses it, records consent and lands in applied on the board', function () {
    Event::fake([CandidateApplicationReceivedEvent::class]);

    [$tenant, $user] = recruiterOfNewTenant();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino', 'retention_default_months' => 6]);
    $opening = publishedOpening($tenant);

    $response = $this->post("/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply", publicApplicationPayload(), ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($response->json('data.reference'))->toStartWith('APP-')
        ->and($response->json('data.message'))->toBe('Grazie, la tua candidatura è stata ricevuta.')
        ->and($response->json('data'))->not->toHaveKey('email');

    useTenant($tenant);
    /** @var CandidateApplication $application */
    $application = CandidateApplication::query()->where('reference', $response->json('data.reference'))->firstOrFail();

    expect($application->stage->value)->toBe('applied')
        ->and($application->email)->toBe('giulia.verdi@example.com')
        ->and($application->source)->toBe('careers_page')
        ->and($application->cv_parse_status->value)->toBe('parsed')
        ->and($application->skills)->toContain('PHP', 'Laravel', 'PostgreSQL', 'Docker')
        ->and($application->extracted_profile['languages'])->toContain(['language' => 'English', 'level' => 'C1'])
        ->and($application->cv_storage_key)->toStartWith("tenants/{$tenant->id}/recruiting/cvs/")
        ->and($application->consent_text_version)->toBe('v1')
        ->and($application->privacy_consent_expires_at->toDateString())->toBe($application->consent_given_at->copy()->addMonthsNoOverflow(6)->toDateString())
        ->and($application->history()->count())->toBe(1);

    Storage::disk('private')->assertExists($application->cv_storage_key);
    Storage::disk('public')->assertMissing($application->cv_storage_key);
    Event::assertDispatched(CandidateApplicationReceivedEvent::class);
    Mail::assertQueued(CandidateStageMail::class);
    expect(CandidateEmail::query()->where('application_id', $application->id)->value('status')?->value)->toBe('queued');

    $board = $this->withHeader('X-Tenant-Id', $tenant->id)->getJson("/api/v1/hr/recruiting/job-openings/{$opening->id}/board");
    $board->assertOk();
    $applied = collect($board->json('data.stages'))->firstWhere('stage', 'applied');
    expect($applied['count'])->toBe(1)
        ->and($applied['applications'][0]['id'])->toBe($application->id)
        ->and(collect($board->json('data.stages'))->pluck('stage')->all())
        ->toBe(['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected']);
});

test('an unparseable CV never blocks the application', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $opening = publishedOpening($tenant);

    $response = $this->post(
        "/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply",
        publicApplicationPayload(['cv' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')]),
        ['Accept' => 'application/json']
    );

    $response->assertCreated();
    useTenant($tenant);
    $application = CandidateApplication::query()->where('reference', $response->json('data.reference'))->firstOrFail();
    expect($application->cv_parse_status->value)->toBe('failed')
        ->and($application->skills)->toBe([]);
});

test('public application validation: consent, consent version, honeypot and CV are enforced', function (array $overrides, string $errorField) {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $opening = publishedOpening($tenant);

    $this->post("/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply", publicApplicationPayload($overrides), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$errorField]);

    useTenant($tenant);
    expect(CandidateApplication::query()->count())->toBe(0);
})->with([
    'consent refused' => [['consent_accepted' => '0'], 'consent_accepted'],
    'outdated consent text' => [['consent_text_version' => 'v0'], 'consent_text_version'],
    'honeypot filled' => [['website' => 'https://spam.example'], 'website'],
    'missing cv' => [['cv' => null], 'cv'],
]);

test('public application refuses CV formats other than pdf, doc and docx', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $opening = publishedOpening($tenant);

    $this->post(
        "/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply",
        publicApplicationPayload(['cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/x-msdownload')]),
        ['Accept' => 'application/json']
    )->assertStatus(422)->assertJsonValidationErrors(['cv']);
});

test('a second open application with the same email on the same opening is a 409 duplicate', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $opening = publishedOpening($tenant);

    $this->post("/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply", publicApplicationPayload(), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->post("/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply", publicApplicationPayload(['email' => 'giulia.verdi@EXAMPLE.com']), ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'duplicate_application');
});

test('applying to an internal or unpublished opening is 404', function () {
    $tenant = Tenant::factory()->create();
    careersSettings($tenant, ['careers_slug' => 'acme-ticino']);
    $internal = publishedOpening($tenant, ['status' => 'internal_only']);
    $draft = publishedOpening($tenant, ['status' => 'draft']);

    foreach ([$internal, $draft] as $opening) {
        $this->post("/api/v1/public/careers/acme-ticino/jobs/{$opening->id}/apply", publicApplicationPayload(), ['Accept' => 'application/json'])
            ->assertNotFound();
    }
});

test('manual intake records a manual consent and refuses openings that are not open', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $draft = publishedOpening($tenant, ['status' => 'draft']);

    $response = $this->withHeader('X-Tenant-Id', $tenant->id)->post('/api/v1/hr/recruiting/applications', [
        'job_opening_id' => $opening->id,
        'first_name' => 'Marco',
        'last_name' => 'Bernasconi',
        'email' => 'marco@example.com',
        'retention_months' => 12,
        'cv' => pdfCv(sampleCvLines()),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.stage', 'applied')
        ->assertJsonPath('data.consent.text_version', 'manual')
        ->assertJsonPath('data.cv.download_url', '/hr/recruiting/applications/'.$response->json('data.id').'/cv')
        ->assertJsonPath('data.offer', null);

    $this->withHeader('X-Tenant-Id', $tenant->id)->get('/api/v1/hr/recruiting/applications/'.$response->json('data.id').'/cv')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=cv.pdf');

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/v1/hr/recruiting/applications', [
        'job_opening_id' => $draft->id,
        'first_name' => 'Anna',
        'last_name' => 'Rossi',
        'email' => 'anna@example.com',
    ])->assertStatus(409)->assertJsonPath('error_code', 'opening_not_open');
});
