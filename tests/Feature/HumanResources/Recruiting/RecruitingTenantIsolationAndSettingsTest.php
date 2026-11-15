<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOffer;

use function Tests\Feature\HumanResources\Recruiting\actAs;
use function Tests\Feature\HumanResources\Recruiting\careersSettings;
use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\recruiterOfNewTenant;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
});

test('a user of tenant B gets 404 on every recruiting endpoint addressing tenant A ids', function () {
    [$tenantA] = recruiterOfNewTenant();
    $opening = publishedOpening($tenantA);
    $application = CandidateApplication::factory()->create(['tenant_id' => $tenantA->id, 'job_opening_id' => $opening->id, 'stage' => 'offer_extended']);
    $offer = JobOffer::factory()->create(['tenant_id' => $tenantA->id, 'application_id' => $application->id]);

    $tenantB = Tenant::factory()->create();
    $userB = User::factory()->create();
    $userB->tenants()->attach($tenantB->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    actAs($userB, $tenantB);
    $headers = ['X-Tenant-Id' => $tenantB->id];

    $requests = [
        ['GET', "/api/v1/hr/recruiting/job-openings/{$opening->id}", []],
        ['PUT', "/api/v1/hr/recruiting/job-openings/{$opening->id}", ['location_site' => 'Chiasso']],
        ['POST', "/api/v1/hr/recruiting/job-openings/{$opening->id}/publish", []],
        ['POST', "/api/v1/hr/recruiting/job-openings/{$opening->id}/close", ['outcome' => 'cancelled']],
        ['GET', "/api/v1/hr/recruiting/job-openings/{$opening->id}/board", []],
        ['GET', "/api/v1/hr/recruiting/applications/{$application->id}", []],
        ['PATCH', "/api/v1/hr/recruiting/applications/{$application->id}", ['notes' => 'x']],
        ['GET', "/api/v1/hr/recruiting/applications/{$application->id}/cv", []],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/reparse-cv", []],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'hr_screening']],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/extend-consent", ['months' => 6]],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/anonymize", []],
        ['GET', "/api/v1/hr/recruiting/applications/{$application->id}/scorecards", []],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/scorecards", ['stage' => 'hr_screening', 'criteria_scores' => [['criterion' => 'X', 'score' => 3]], 'recommendation' => 'hire']],
        ['GET', "/api/v1/hr/recruiting/applications/{$application->id}/offer", []],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/offer", ['proposed_salary' => 1000, 'start_date' => '2027-01-01']],
        ['POST', "/api/v1/hr/recruiting/applications/{$application->id}/hire", []],
        ['POST', "/api/v1/hr/recruiting/offers/{$offer->id}/mark-signed", []],
        ['POST', "/api/v1/hr/recruiting/offers/{$offer->id}/decline", []],
    ];

    foreach ($requests as [$method, $uri, $payload]) {
        $this->withHeaders($headers)->json($method, $uri, $payload)->assertNotFound();
    }

    $this->withHeaders($headers)->getJson('/api/v1/hr/recruiting/applications')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withHeaders($headers)->getJson('/api/v1/hr/recruiting/job-openings')->assertOk()->assertJsonPath('meta.total', 0);

    useTenant($tenantA);
    expect($application->fresh()->stage->value)->toBe('offer_extended')
        ->and($application->fresh()->anonymized_at)->toBeNull();
});

test('settings return defaults with all seven pipeline stages and validate updates', function () {
    [$tenant] = recruiterOfNewTenant();
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->withHeaders($headers)->getJson('/api/v1/hr/recruiting/settings')
        ->assertOk()
        ->assertJsonPath('data.careers.enabled', false)
        ->assertJsonPath('data.careers.slug', null)
        ->assertJsonPath('data.retention.default_months', 6)
        ->assertJsonCount(7, 'data.pipeline.stages')
        ->assertJsonPath('data.pipeline.stages.0.stage', 'applied')
        ->assertJsonPath('data.pipeline.stages.6.stage', 'rejected')
        ->assertJsonPath('data.pipeline.stages.1.labels.de-CH', 'HR-Screening');

    $this->withHeaders($headers)->putJson('/api/v1/hr/recruiting/settings', [
        'careers' => ['enabled' => true, 'slug' => 'Not A Slug'],
    ])->assertStatus(422)->assertJsonValidationErrors(['careers.slug']);

    $this->withHeaders($headers)->putJson('/api/v1/hr/recruiting/settings', [
        'pipeline' => ['stages' => [['stage' => 'applied', 'enabled' => false]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['pipeline.stages.0.enabled']);

    $this->withHeaders($headers)->putJson('/api/v1/hr/recruiting/settings', [
        'careers' => ['enabled' => true, 'slug' => 'acme-ticino', 'intro' => ['it-CH' => 'Lavora con noi']],
        'retention' => ['default_months' => 12],
        'pipeline' => ['stages' => [['stage' => 'hr_screening', 'enabled' => false, 'auto_email' => true, 'labels' => ['en' => 'Phone screen']]]],
    ])->assertOk()
        ->assertJsonPath('data.careers.slug', 'acme-ticino')
        ->assertJsonPath('data.careers.intro.it-CH', 'Lavora con noi')
        ->assertJsonPath('data.retention.default_months', 12)
        ->assertJsonPath('data.pipeline.stages.1.enabled', false)
        ->assertJsonPath('data.pipeline.stages.1.auto_email', true)
        ->assertJsonPath('data.pipeline.stages.1.labels.en', 'Phone screen');

    $this->getJson('/api/v1/public/careers/acme-ticino')->assertOk()->assertJsonPath('data.retention_months', 12);

    $otherTenant = Tenant::factory()->create();
    careersSettings($otherTenant, ['careers_slug' => 'taken-slug']);
    useTenant($tenant);

    $this->withHeaders($headers)->putJson('/api/v1/hr/recruiting/settings', [
        'careers' => ['slug' => 'taken-slug'],
    ])->assertStatus(422)->assertJsonValidationErrors(['careers.slug']);
});
