<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\HumanResources\Mail\CandidateStageMail;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;
use Modules\HumanResources\Models\InterviewScorecard;

use function Tests\Feature\HumanResources\Recruiting\careersSettings;
use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\recruiterOfNewTenant;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
});

test('moving a candidate records history and sends the stage email in the candidate locale', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $application = CandidateApplication::factory()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $opening->id,
        'locale' => 'it-CH',
    ]);

    $this->withHeader('X-Tenant-Id', $tenant->id)->putJson('/api/v1/hr/recruiting/email-templates/technical_interview/it-CH', [
        'subject' => 'Colloquio tecnico per {{job_title}}',
        'body' => 'Ciao {{candidate_name}}, ti aspettiamo da {{company_name}}.',
        'is_enabled' => true,
    ])->assertOk()->assertJsonPath('data.is_default', false);

    $response = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", [
        'stage' => 'technical_interview',
        'note' => 'Strong profile',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.stage', 'technical_interview')
        ->assertJsonPath('data.history.0.from_stage', 'applied')
        ->assertJsonPath('data.history.0.to_stage', 'technical_interview')
        ->assertJsonPath('data.emails.0.status', 'queued')
        ->assertJsonPath('data.emails.0.subject', 'Colloquio tecnico per Sviluppatore PHP');

    Mail::assertQueued(CandidateStageMail::class, fn (CandidateStageMail $mail): bool => $mail->hasTo($application->email)
        && str_contains($mail->renderedHtml, 'ti aspettiamo da'));

    useTenant($tenant);
    expect(ApplicationStageHistory::query()->where('application_id', $application->id)->count())->toBe(1);
});

test('a disabled template logs the stage email as skipped', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $application = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id, 'locale' => 'en']);

    $this->withHeader('X-Tenant-Id', $tenant->id)->putJson('/api/v1/hr/recruiting/email-templates/technical_interview/en', [
        'subject' => 'Interview',
        'body' => 'Hello',
        'is_enabled' => false,
    ])->assertOk();

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'technical_interview'])
        ->assertOk()
        ->assertJsonPath('data.emails.0.status', 'skipped');

    Mail::assertNothingQueued();
});

test('illegal moves return the documented 409 codes', function () {
    [$tenant] = recruiterOfNewTenant();
    careersSettings($tenant, ['pipeline' => ['hr_screening' => ['enabled' => false]]]);
    $opening = publishedOpening($tenant);
    $application = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id]);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'hr_screening'])
        ->assertStatus(409)->assertJsonPath('error_code', 'stage_disabled');

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'hired'])
        ->assertStatus(409)->assertJsonPath('error_code', 'invalid_stage_transition');

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'rejected'])
        ->assertStatus(422)->assertJsonValidationErrors(['rejection_reason_code']);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", [
        'stage' => 'rejected',
        'rejection_reason_code' => 'salary_mismatch',
        'send_email' => false,
    ])->assertOk()->assertJsonPath('data.rejection.reason_code', 'salary_mismatch');

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'technical_interview'])
        ->assertStatus(409)->assertJsonPath('error_code', 'invalid_stage_transition');

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'applied', 'send_email' => false])
        ->assertOk()
        ->assertJsonPath('data.stage', 'applied')
        ->assertJsonPath('data.rejection', null);

    Mail::assertNothingQueued();
});

test('reordering inside a column persists the board position', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $first = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id, 'board_position' => 0]);
    $second = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id, 'board_position' => 1]);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$second->id}/move", ['stage' => 'applied', 'board_position' => 0])
        ->assertOk()
        ->assertJsonPath('data.board_position', 0);

    useTenant($tenant);
    expect($first->fresh()->board_position)->toBe(1)
        ->and(ApplicationStageHistory::query()->count())->toBe(0);
});

test('scorecards of two interviewers are averaged and resubmitting updates instead of duplicating', function () {
    [$tenant, $firstInterviewer] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $application = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id, 'stage' => 'technical_interview']);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/scorecards", [
        'stage' => 'technical_interview',
        'criteria_scores' => [
            ['criterion' => 'PHP', 'score' => 5, 'comment' => 'Excellent'],
            ['criterion' => 'Communication', 'score' => 4],
        ],
        'recommendation' => 'strong_hire',
    ])->assertCreated()->assertJsonPath('data.overall_score', 4.5);

    $secondInterviewer = User::factory()->create();
    $secondInterviewer->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    recruitingActAs($secondInterviewer, $tenant);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/scorecards", [
        'stage' => 'technical_interview',
        'criteria_scores' => [['criterion' => 'PHP', 'score' => 3]],
        'recommendation' => 'hire',
    ])->assertCreated()->assertJsonPath('data.overall_score', 3.0);

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/scorecards", [
        'stage' => 'technical_interview',
        'criteria_scores' => [['criterion' => 'PHP', 'score' => 2], ['criterion' => 'Teamwork', 'score' => 3]],
        'recommendation' => 'no_hire',
    ])->assertOk()->assertJsonPath('data.overall_score', 2.5);

    $this->withHeader('X-Tenant-Id', $tenant->id)->getJson("/api/v1/hr/recruiting/applications/{$application->id}/scorecards")
        ->assertOk()->assertJsonCount(2, 'data');

    $detail = $this->withHeader('X-Tenant-Id', $tenant->id)->getJson("/api/v1/hr/recruiting/applications/{$application->id}");
    $detail->assertOk()->assertJsonPath('data.scorecards_count', 2)->assertJsonPath('data.average_score', 3.5);

    useTenant($tenant);
    expect(InterviewScorecard::query()->where('application_id', $application->id)->count())->toBe(2)
        ->and(InterviewScorecard::query()->where('interviewer_user_id', (string) $firstInterviewer->id)->value('overall_score'))->toBe('4.50');
});

test('email templates list defaults for every enabled stage and locale', function () {
    [$tenant] = recruiterOfNewTenant();
    careersSettings($tenant, ['pipeline' => ['manager_interview' => ['enabled' => false]]]);

    $response = $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/hr/recruiting/email-templates');

    $response->assertOk()->assertJsonCount(6 * 5, 'data');
    expect(collect($response->json('data'))->pluck('stage')->unique()->values()->all())->not->toContain('manager_interview')
        ->and(collect($response->json('data'))->every(fn (array $template): bool => $template['is_default'] === true))->toBeTrue();

    $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/hr/recruiting/email-templates?stage=applied&locale=de-CH')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', '{{company_name}} - {{job_title}}: {{stage_label}}');

    useTenant($tenant);
    expect(CandidateEmail::query()->count())->toBe(0);
});
