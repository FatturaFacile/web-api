<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Database\Seeders\AcademyContextualHelpSeeder;
use Database\Seeders\AcademyLearningContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Academy\Models\AcademyLearningTrack;
use Modules\Academy\Models\ContextualHelpArticle;
use Tests\Support\AcademyScenario;
use Tests\Support\CopilotScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = AcademyScenario::tenant('Alfa', true);
    $this->other = AcademyScenario::tenant('Beta', true);
    $this->teacher = CopilotScenario::member($this->tenant, 'member');
    CopilotScenario::actAs($this, $this->teacher, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function helpArticle(string $route, string $locale, string $title): void
{
    app(TenantContext::class)->runAsGlobal(fn () => ContextualHelpArticle::query()->create([
        'tenant_id' => null, 'route_pattern' => $route, 'locale' => $locale, 'title' => $title, 'body_markdown' => 'x', 'field_tooltips_json' => ['f' => $title],
    ]));
}

test('the seeded help covers at least twelve core routes in all five locales', function (): void {
    $this->seed(AcademyContextualHelpSeeder::class);

    $routes = ContextualHelpArticle::query()->distinct()->pluck('route_pattern');
    expect($routes->count())->toBeGreaterThanOrEqual(12);

    foreach (['it-CH', 'it-IT', 'de-CH', 'fr-CH', 'en'] as $locale) {
        expect(ContextualHelpArticle::query()->where('locale', $locale)->count())->toBe($routes->count());
    }

    $this->getJson('/api/v1/academy/contextual-help?route=/sales/billing/plans&locale=de-CH')
        ->assertOk()
        ->assertJsonPath('data.locale', 'de-CH')
        ->assertJsonPath('data.title', 'Rechnungspläne')
        ->assertJsonStructure(['data' => ['field_tooltips' => ['name', 'target_date', 'percentage', 'amount']]]);
});

test('contextual help falls back from it-CH to it-IT and then to English', function (): void {
    helpArticle('/contacts', 'it-IT', 'Contatti IT');
    helpArticle('/contacts', 'en', 'Contacts EN');
    helpArticle('/products', 'en', 'Products EN');

    $this->getJson('/api/v1/academy/contextual-help?route=/contacts&locale=it-CH')->assertOk()->assertJsonPath('data.locale', 'it-IT')->assertJsonPath('data.title', 'Contatti IT');
    $this->getJson('/api/v1/academy/contextual-help?route=/products&locale=it-CH')->assertOk()->assertJsonPath('data.locale', 'en');
    $this->getJson('/api/v1/academy/contextual-help?route=/contacts&locale=de-CH')->assertOk()->assertJsonPath('data.locale', 'en');
    $this->getJson('/api/v1/academy/contextual-help?route=/nothing&locale=en')->assertNotFound();
});

test('a route with an identifier matches the parameterised pattern and the most specific pattern wins', function (): void {
    helpArticle('/invoices/:id', 'en', 'Detail');
    helpArticle('/invoices/new', 'en', 'New');

    $this->getJson('/api/v1/academy/contextual-help?route=/invoices/new?tab=1&locale=en')->assertOk()->assertJsonPath('data.title', 'New');
    $this->getJson('/api/v1/academy/contextual-help?route=/invoices/0194-abc/&locale=en')->assertOk()->assertJsonPath('data.title', 'Detail');
});

test('every screen gets the generic wildcard help and a specific article still wins', function (): void {
    helpArticle('/*', 'en', 'Generic');
    helpArticle('/hr/*', 'en', 'HR section');
    helpArticle('/hr/employees', 'en', 'Employees');

    $this->getJson('/api/v1/academy/contextual-help?route=/anything/deep/page&locale=en')->assertOk()->assertJsonPath('data.title', 'Generic');
    $this->getJson('/api/v1/academy/contextual-help?route=/hr/leave/requests&locale=en')->assertOk()->assertJsonPath('data.title', 'HR section');
    $this->getJson('/api/v1/academy/contextual-help?route=/hr/employees&locale=en')->assertOk()->assertJsonPath('data.title', 'Employees');
});

test('a teacher creates a cohort, a student joins by code and the dashboard shows the students', function (): void {
    $this->seed(AcademyLearningContentSeeder::class);
    $track = AcademyLearningTrack::query()->where('code', 'hr-and-payroll-ch-it')->firstOrFail();

    $cohort = $this->postJson('/api/v1/academy/classroom/cohorts', ['name' => 'Class 4A', 'track_ids' => [$track->id]])->assertCreated();
    $cohortId = $cohort->json('data.id');
    $code = $cohort->json('data.join_code');

    $student = CopilotScenario::member($this->tenant, 'member');
    CopilotScenario::actAs($this, $student, $this->tenant);
    $this->postJson('/api/v1/academy/classroom/join', ['join_code' => strtolower($code)])->assertOk()->assertJsonPath('data.cohort_id', $cohortId);
    $this->postJson('/api/v1/academy/classroom/join', ['join_code' => $code])->assertOk();
    $this->getJson("/api/v1/academy/classroom/cohorts/{$cohortId}/dashboard")->assertForbidden();

    CopilotScenario::actAs($this, $this->teacher, $this->tenant);
    $this->getJson("/api/v1/academy/classroom/cohorts/{$cohortId}/dashboard")
        ->assertOk()
        ->assertJsonCount(1, 'data.students')
        ->assertJsonPath('data.students.0.user_id', $student->id)
        ->assertJsonPath('data.students.0.missions_total', 3)
        ->assertJsonPath('data.assignments.0.track_code', 'hr-and-payroll-ch-it');
});

test('cohorts are invisible and unjoinable from another tenant', function (): void {
    $cohort = $this->postJson('/api/v1/academy/classroom/cohorts', ['name' => 'Class 4A'])->assertCreated();

    $outsider = CopilotScenario::member($this->other, 'owner');
    CopilotScenario::actAs($this, $outsider, $this->other);

    $this->postJson('/api/v1/academy/classroom/join', ['join_code' => $cohort->json('data.join_code')])->assertNotFound();
    $this->getJson('/api/v1/academy/classroom/cohorts/'.$cohort->json('data.id').'/dashboard')->assertNotFound();
});

test('creating a cohort validates the name and the tracks', function (): void {
    $this->postJson('/api/v1/academy/classroom/cohorts', [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/academy/classroom/cohorts', ['name' => 'X', 'track_ids' => [(string) Str::uuid()]])->assertUnprocessable();
});
