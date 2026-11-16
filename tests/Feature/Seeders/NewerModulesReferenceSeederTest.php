<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CreditManagementReferenceSeeder;
use Database\Seeders\NewerModulesReferenceSeeder;
use Database\Seeders\ReturnsReferenceSeeder;
use Database\Seeders\SupplierPortalReferenceSeeder;
use Database\Seeders\SustainabilityReferenceSeeder;
use Database\Seeders\TalentLearningReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenants = collect(['One', 'Two'])->map(fn (string $name): Tenant => Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']));
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Row counts of the given tables, per tenant.
 *
 * @param  list<string>  $tables
 * @return array<string, int>
 */
function seededCounts(array $tables, string $tenantId): array
{
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    return $counts;
}

test('the credit management seeder fills the scorecard, the rating bands and the dunning strategy once per company', function (): void {
    $tables = ['credit_scorecard_factors', 'credit_rating_bands', 'credit_dunning_strategies', 'credit_dunning_steps'];

    $this->seed(CreditManagementReferenceSeeder::class);
    $first = seededCounts($tables, (string) $this->tenants[0]->id);

    expect($first)->toBe(['credit_scorecard_factors' => 5, 'credit_rating_bands' => 5, 'credit_dunning_strategies' => 1, 'credit_dunning_steps' => 6])
        ->and(DB::table('credit_dunning_strategies')->where('tenant_id', $this->tenants[0]->id)->value('is_default'))->toEqual(1)
        ->and((string) DB::table('credit_scorecard_factors')->where('tenant_id', $this->tenants[0]->id)->sum('weight'))->toBeIn(['100', '100.00']);

    $this->seed(CreditManagementReferenceSeeder::class);

    foreach ($this->tenants as $tenant) {
        expect(seededCounts($tables, (string) $tenant->id))->toBe($first);
    }
});

test('the credit management seeder keeps the choices a company made', function (): void {
    $tenantId = (string) $this->tenants[0]->id;
    $this->seed(CreditManagementReferenceSeeder::class);

    DB::table('credit_rating_bands')->where('tenant_id', $tenantId)->where('code', 'A')->update(['name' => 'Renamed']);
    DB::table('credit_rating_bands')->where('tenant_id', $tenantId)->where('code', 'B')->update(['deleted_at' => now()]);
    $this->seed(CreditManagementReferenceSeeder::class);

    expect(DB::table('credit_rating_bands')->where('tenant_id', $tenantId)->where('code', 'A')->value('name'))->toBe('Renamed')
        ->and(DB::table('credit_rating_bands')->where('tenant_id', $tenantId)->where('code', 'B')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(DB::table('credit_rating_bands')->where('tenant_id', $tenantId)->count())->toBe(5);
});

test('the talent and learning seeder fills the compliance courses and the engagement survey template once per company', function (): void {
    $tables = ['tl_courses', 'tl_surveys', 'tl_survey_questions'];

    $this->seed(TalentLearningReferenceSeeder::class);
    $first = seededCounts($tables, (string) $this->tenants[0]->id);

    expect($first)->toBe(['tl_courses' => 7, 'tl_surveys' => 1, 'tl_survey_questions' => 7])
        ->and(DB::table('tl_courses')->where('tenant_id', $this->tenants[0]->id)->where('status', '!=', 'draft')->count())->toBe(0)
        ->and(DB::table('tl_survey_questions')->where('tenant_id', $this->tenants[0]->id)->where('question_type', 'enps')->count())->toBe(1);

    $this->seed(TalentLearningReferenceSeeder::class);

    foreach ($this->tenants as $tenant) {
        expect(seededCounts($tables, (string) $tenant->id))->toBe($first);
    }
});

test('the supplier portal, sustainability and returns seeders are idempotent', function (): void {
    $tables = ['sp_document_requirements', 'esg_kpi_definitions', 'returns_reason_codes', 'returns_settings'];
    $tables = array_values(array_filter($tables, static fn (string $table): bool => Schema::hasTable($table)));

    foreach ([SupplierPortalReferenceSeeder::class, SustainabilityReferenceSeeder::class, ReturnsReferenceSeeder::class] as $seeder) {
        $this->seed($seeder);
    }

    $first = seededCounts($tables, (string) $this->tenants[1]->id);

    expect(array_sum($first))->toBeGreaterThan(0)
        ->and(DB::table('returns_reason_codes')->where('tenant_id', $this->tenants[1]->id)->count())->toBe(6);

    foreach ([SupplierPortalReferenceSeeder::class, SustainabilityReferenceSeeder::class, ReturnsReferenceSeeder::class] as $seeder) {
        $this->seed($seeder);
    }

    foreach ($this->tenants as $tenant) {
        expect(seededCounts($tables, (string) $tenant->id))->toBe($first);
    }
});

test('the aggregate seeder of the newer modules runs twice without duplicates', function (): void {
    $tables = ['credit_scorecard_factors', 'credit_rating_bands', 'credit_dunning_steps', 'tl_courses', 'tl_survey_questions', 'returns_reason_codes'];

    $this->seed(NewerModulesReferenceSeeder::class);
    $first = seededCounts($tables, (string) $this->tenants[0]->id);
    $this->seed(NewerModulesReferenceSeeder::class);

    expect(seededCounts($tables, (string) $this->tenants[0]->id))->toBe($first)
        ->and(seededCounts($tables, (string) $this->tenants[1]->id))->toBe($first);
});
