<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Services\LegalParameterService;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->year = (int) now()->year;
    $this->coach = Member::factory()->create();
});

afterEach(fn () => associationsTearDown());

function sportCollaborator(object $test, array $extra = []): string
{
    return $test->postJson('/api/v1/associations/catalogs/sport-collaborators', ['member_id' => $test->coach->id, 'role' => 'Coach', 'starts_on' => now()->startOfYear()->toDateString(), ...$extra])->assertCreated()->json('data.id');
}

function sportPay(object $test, string $collaborator, string $gross, string $month, array $extra = []): void
{
    $test->postJson('/api/v1/associations/sport/compensations', [
        'collaborator_id' => $collaborator, 'period' => $test->year.'-'.$month, 'paid_on' => now()->startOfYear()->addMonths((int) $month - 1)->addDays(9)->toDateString(), 'gross_amount' => $gross, ...$extra,
    ])->assertCreated();
}

test('compensations are summed per person per year and checked against the thresholds of the year, unseeded until configured', function (): void {
    actAs($this, 'secretary');
    $collaborator = sportCollaborator($this);
    sportPay($this, $collaborator, '400.00', '01', ['withholding_amount' => '0.00', 'contribution_amount' => '12.00']);
    sportPay($this, $collaborator, '500.00', '02');

    $report = $this->getJson("/api/v1/associations/sport/{$this->year}/thresholds")->assertOk()->json('data');
    expect($report['people'][0]['gross'])->toBe('900.00')
        ->and($report['people'][0]['contribution_threshold']['status'])->toBe('not_configured')
        ->and($report['thresholds']['tax']['configured'])->toBeFalse();

    LegalParameter::query()->create(['code' => LegalParameterService::SportContributionThreshold, 'year' => $this->year, 'value' => '1000', 'source_reference' => 'test value, not a legal figure']);
    LegalParameter::query()->create(['code' => LegalParameterService::SportTaxThreshold, 'year' => $this->year, 'value' => '800', 'source_reference' => 'test value, not a legal figure']);
    LegalParameter::query()->create(['code' => LegalParameterService::SportWarningPercent, 'year' => $this->year, 'value' => '85', 'source_reference' => 'company policy']);

    $person = $this->getJson("/api/v1/associations/sport/{$this->year}/thresholds")->json('data.people.0');
    expect($person['contribution_threshold']['status'])->toBe('warning')
        ->and($person['tax_threshold']['status'])->toBe('exceeded')
        ->and($person['contributions'])->toBe('12.00');
});

test('a compensation must use a contract of the same collaborator', function (): void {
    actAs($this, 'admin');
    $first = sportCollaborator($this);
    $second = sportCollaborator($this, ['member_id' => Member::factory()->create()->id, 'role' => 'Referee']);
    $contract = $this->postJson('/api/v1/associations/catalogs/sport-contracts', ['collaborator_id' => $first, 'kind' => 'coordinated collaboration', 'starts_on' => now()->startOfYear()->toDateString()])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/associations/sport/compensations', ['collaborator_id' => $second, 'contract_id' => $contract, 'period' => $this->year.'-03', 'paid_on' => now()->toDateString(), 'gross_amount' => '10.00'])->assertUnprocessable();
    sportPay($this, $first, '10.00', '03', ['contract_id' => $contract]);
});

test('the single book, the accountant export and the registry export list the right people', function (): void {
    $service = app(MembershipService::class);
    $athlete = Member::factory()->create();
    $service->activate($service->request($athlete, AssociativeYear::factory()->create(), MembershipType::factory()->create()));

    actAs($this, 'accountant');
    $collaborator = sportCollaborator($this);
    sportPay($this, $collaborator, '250.00', '04', ['withholding_amount' => '20.00', 'contribution_amount' => '5.00', 'hours' => 10]);

    $book = $this->getJson("/api/v1/associations/sport/{$this->year}/single-book")->assertOk()->json('data');
    expect($book['rows'])->toHaveCount(1)->and($book['rows'][0][0])->toBe($this->year.'-04')->and($book['rows'][0][10])->toBe('225.00');

    expect((string) $this->get("/api/v1/associations/sport/{$this->year}/accountant-csv")->getContent())->toContain($this->coach->tax_code);
    $registry = (string) $this->get("/api/v1/associations/sport/{$this->year}/registry-csv")->getContent();
    expect($registry)->toContain($athlete->tax_code)->toContain($this->coach->tax_code);
    expect((string) $this->get("/api/v1/associations/sport/{$this->year}/single-book/csv")->getContent())->toContain('Coach');
});

test('insurance expiries of collaborators are listed with the counts of expiring certificates', function (): void {
    actAs($this, 'secretary');
    sportCollaborator($this, ['insurance_policy' => 'P-9', 'insurance_expires_on' => now()->addDays(10)->toDateString()]);
    sportCollaborator($this, ['member_id' => Member::factory()->create()->id, 'insurance_expires_on' => now()->addYear()->toDateString()]);

    $this->getJson('/api/v1/associations/sport/expiries?days=30')->assertOk()->assertJsonCount(1, 'data.insurance')->assertJsonPath('data.medical_certificates_expiring', 0);
});

test('compensations of another company are invisible and other roles are refused', function (): void {
    actAs($this, 'admin');
    sportPay($this, sportCollaborator($this), '100.00', '05');

    actAs($this, 'admin', tenant: $this->otherTenant);
    $this->getJson('/api/v1/associations/sport/compensations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/associations/sport/{$this->year}/thresholds")->assertJsonCount(0, 'data.people');

    actAs($this, 'member');
    $this->getJson('/api/v1/associations/sport/compensations')->assertForbidden();
});
