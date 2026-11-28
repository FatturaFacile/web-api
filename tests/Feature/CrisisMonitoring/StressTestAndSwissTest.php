<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CrisisMonitoring\Application\Services\RangruecktrittAgreementBuilder;
use Modules\CrisisMonitoring\Models\AdequateOrganizationalAssetReportCcii;
use Modules\CrisisMonitoring\Models\DscrMonthlyProjection;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;

require_once __DIR__.'/CrisisMonitoringSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    crisisSetUp($this);
});

it('compares the baseline with a revenue drop without saving anything', function (): void {
    crisisScenario($this);

    $data = $this->postJson('/api/v1/crisis-monitoring/stress-test', ['revenue_drop_percent' => 50])->assertOk()->json('data');

    // Receipts 20,000 -> 10,000: (10,000 - 3,000 + 50,000) / 10,500 = 5.43 against 6.38.
    expect($data['baseline']['dscr']['treasury']['m6']['value'])->toBe('6.38')
        ->and($data['stressed']['dscr']['treasury']['m6']['value'])->toBe('5.43')
        ->and($data['deltas']['dscr_treasury_m6'])->toBe('-0.95')
        ->and($data['shock']['revenue_drop_percent'])->toBe('50.00')
        ->and($data['stressed']['liquidity'])->toHaveKeys(['min_cumulative_cash', 'first_negative_month', 'liquidity_gap'])
        ->and($data['stressed']['capital'])->toHaveKeys(['net_assets', 'coverage_ratio', 'status']);

    expect(DscrMonthlyProjection::query()->count())->toBe(0)->and(AdequateOrganizationalAssetReportCcii::query()->count())->toBe(0);
});

it('applies an extraordinary outflow and shows the liquidity gap it opens', function (): void {
    crisisScenario($this);

    $stressed = $this->postJson('/api/v1/crisis-monitoring/stress-test', ['one_off_outflow_amount' => 100000])->assertOk()->json('data.stressed');

    // 50,000 + 20,000 - 3,000 - 10,500 - 100,000 = -43,500 at the lowest point.
    expect($stressed['liquidity']['min_cumulative_cash'])->toBe('-43500.00')
        ->and($stressed['liquidity']['liquidity_gap'])->toBe('43500.00')
        ->and($stressed['liquidity']['first_negative_month'])->not->toBeNull()
        ->and($stressed['swiss_overall'])->toBe('red');
});

it('delays the collections beyond the six month window with the DSO shift', function (): void {
    crisisScenario($this);

    $data = $this->postJson('/api/v1/crisis-monitoring/stress-test', ['dso_delay_days' => 180])->assertOk()->json('data');

    // The receipt moves from day 10 to day 190: out of the six months, still inside the twelve.
    expect($data['stressed']['dscr']['treasury']['m6']['value'])->toBe('4.48')
        ->and($data['stressed']['dscr']['treasury']['m12']['value'])->toBe('6.38');
});

it('adds the interest of a rate hike on the variable debt to the debt service', function (): void {
    crisisScenario($this);
    $this->putJson('/api/v1/crisis-monitoring/profile', ['variable_debt_amount' => '1200000'])->assertOk();

    $data = $this->postJson('/api/v1/crisis-monitoring/stress-test', ['interest_rate_bps' => 250])->assertOk()->json('data');

    // 250 bps on 1,200,000 = 30,000 a year = 2,500 a month: over six months the debt service grows from 10,500 to 25,500.
    expect($data['stressed']['dscr']['treasury']['m6']['denominator'])->toBe('25500.00')
        ->and($data['stressed']['dscr']['treasury']['m6']['value'])->toBe('2.63')
        ->and($data['deltas']['dscr_treasury_m6'])->toBe('-3.75');
});

it('leaves the baseline untouched by a neutral shock and validates the ranges', function (): void {
    crisisScenario($this);

    $data = $this->postJson('/api/v1/crisis-monitoring/stress-test', [])->assertOk()->json('data');

    expect($data['deltas']['dscr_treasury_m6'])->toBe('0.00')->and($data['deltas']['min_cumulative_cash'])->toBe('0.00');

    $this->postJson('/api/v1/crisis-monitoring/stress-test', ['revenue_drop_percent' => 101])->assertStatus(422);
    $this->postJson('/api/v1/crisis-monitoring/stress-test', ['dso_delay_days' => 181])->assertStatus(422);
    $this->postJson('/api/v1/crisis-monitoring/stress-test', ['interest_rate_bps' => 2001])->assertStatus(422);
    $this->postJson('/api/v1/crisis-monitoring/stress-test', ['one_off_outflow_amount' => -1])->assertStatus(422);
});

it('reports the capital loss and the over-indebtedness of the Swiss cockpit', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', [
        'jurisdiction' => 'CH', 'share_capital' => '100000', 'statutory_reserves' => '20000', 'net_equity_override' => '50000',
        'assets_going_concern' => '900000', 'assets_liquidation' => '500000', 'total_liabilities_override' => '1000000',
    ])->assertOk();

    $swiss = $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->assertJsonPath('data.jurisdiction', 'CH')->json('data.swiss');

    expect($swiss['art725a']['capital_loss'])->toBeTrue()->and($swiss['art725a']['status'])->toBe('red')
        ->and($swiss['art725a']['protected_capital'])->toBe('120000.00')
        ->and($swiss['art725b']['overindebted'])->toBeTrue()
        ->and($swiss['art725b']['basis'])->toBe('going_concern')
        ->and($swiss['art725b']['shortfall'])->toBe('100000.00')
        ->and($swiss['art725b']['court_notification_required'])->toBeTrue()
        ->and($swiss['overall_status'])->toBe('red')
        ->and($swiss['subordination']['required_claim_amount'])->toBe('100000.00');
});

it('computes the exact subordination still required and drops it to zero with an active agreement', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['assets_going_concern' => '900000', 'assets_liquidation' => '500000', 'total_liabilities_override' => '1000000'])->assertOk();

    $required = $this->getJson('/api/v1/crisis-monitoring/rangruecktritt/required?horizon_months=12&interest_rate=5')->assertOk()->json('data');

    // 100,000 to cover; a new agreement at 5 % for twelve months: 100,000 / 1.05 = 95,238.10 rounded up to the cent.
    expect($required['required_claim_amount'])->toBe('100000.00')
        ->and($required['required_principal_amount'])->toBe('95238.10')
        ->and($required['assumed_rate'])->toBe('5.00')
        ->and($required['court_notification_required'])->toBeTrue();

    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload(['interest_rate' => '0', 'principal' => '100000']))->assertCreated();

    $covered = $this->getJson('/api/v1/crisis-monitoring/rangruecktritt/required?horizon_months=12&interest_rate=5')->assertOk()->json('data');
    $dashboard = $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->json('data.swiss');

    expect($covered['active_claims_covered'])->toBe('100000.00')
        ->and($covered['required_claim_amount'])->toBe('0.00')
        ->and($covered['court_notification_required'])->toBeFalse()
        ->and($dashboard['art725b']['court_notification_required'])->toBeFalse()
        ->and($dashboard['art725b']['status'])->toBe('yellow');
});

it('lets the user choose the valuation basis to cure', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['assets_going_concern' => '900000', 'assets_liquidation' => '500000', 'total_liabilities_override' => '1000000'])->assertOk();

    $liquidation = $this->getJson('/api/v1/crisis-monitoring/rangruecktritt/required?basis=liquidation&interest_rate=0')->assertOk()->json('data');

    expect($liquidation['basis'])->toBe('liquidation')->and($liquidation['shortfall'])->toBe('500000.00')->and($liquidation['required_principal_amount'])->toBe('500000.00');

    $this->getJson('/api/v1/crisis-monitoring/rangruecktritt/required?basis=wrong')->assertStatus(422);
});

it('manages the registry of the subordinated loans with the accrued interest and the claim', function (): void {
    $id = $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload(['effective_from' => now()->subDays(100)->toDateString()]))
        ->assertCreated()->json('data.id');

    $loan = $this->getJson('/api/v1/crisis-monitoring/subordinated-loans')->assertOk()->assertJsonCount(1, 'data')->json('data.0');

    // 100 days accrued at 5 % on 100,000 = 1,369.86; the claim at twelve months adds the future interest.
    expect($loan['id'])->toBe($id)
        ->and($loan['accrued_interest'])->toBe('1369.86')
        ->and((float) $loan['claim_at_horizon'])->toBeGreaterThan(106000.0)->toBeLessThan(106400.0)
        ->and($loan['status'])->toBe('active');

    $this->putJson('/api/v1/crisis-monitoring/subordinated-loans/'.$id, crisisLoanPayload(['status' => 'released', 'notes' => 'repaid']))->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.notes', 'repaid');
    $this->deleteJson('/api/v1/crisis-monitoring/subordinated-loans/'.$id)->assertOk();

    $this->getJson('/api/v1/crisis-monitoring/subordinated-loans')->assertOk()->assertJsonCount(0, 'data');
    expect(SubordinatedLoanRangruecktritt::withTrashed()->count())->toBe(1);
});

it('validates the subordinated loan', function (): void {
    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload(['principal' => '0']))->assertStatus(422)->assertJsonValidationErrors('principal');
    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload(['scope' => 'everything']))->assertStatus(422)->assertJsonValidationErrors('scope');
    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload(['deferral_end_date' => now()->subYear()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('deferral_end_date');
    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', [])->assertStatus(422);
});

it('keeps the loans of a company invisible and untouchable for another company', function (): void {
    $id = $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload())->assertCreated()->json('data.id');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/crisis-monitoring/subordinated-loans')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson('/api/v1/crisis-monitoring/subordinated-loans/'.$id, crisisLoanPayload())->assertNotFound();
    $this->deleteJson('/api/v1/crisis-monitoring/subordinated-loans/'.$id)->assertNotFound();
    $this->get('/api/v1/crisis-monitoring/subordinated-loans/'.$id.'/agreement/pdf')->assertNotFound();
});

it('downloads the bilingual subordination agreement as a PDF', function (): void {
    $id = $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload())->assertCreated()->json('data.id');

    foreach (['de', 'it'] as $locale) {
        $response = $this->get('/api/v1/crisis-monitoring/subordinated-loans/'.$id.'/agreement/pdf?locale='.$locale)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/pdf')->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('renders the agreement in German next to the language of the reader', function (): void {
    $id = $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload())->assertCreated()->json('data.id');
    $loan = SubordinatedLoanRangruecktritt::query()->findOrFail($id);

    $html = app(RangruecktrittAgreementBuilder::class)->html('Acme AG', $loan, ['accrued_interest' => '10.00', 'future_interest' => '20.00', 'claim' => '100030.00'], 'fr', now()->toImmutable());

    expect($html)->toContain('Rangrücktritt')->toContain('Mario Rossi')->toContain('100&#039;030.00')->toContain('FR')->not->toContain('crisisApi.');
});

it('reads and saves the crisis profile with its validation', function (): void {
    $this->getJson('/api/v1/crisis-monitoring/profile')->assertOk()->assertJsonPath('data.jurisdiction', 'IT')->assertJsonPath('data.legal_form', 'corporation')->assertJsonPath('data.going_concern_probable', true);

    $this->putJson('/api/v1/crisis-monitoring/profile', ['jurisdiction' => 'CH', 'employees_count' => 12, 'share_capital' => '100000', 'net_equity_override' => '-2500.50', 'going_concern_probable' => false])
        ->assertOk()->assertJsonPath('data.jurisdiction', 'CH')->assertJsonPath('data.share_capital', '100000.00')->assertJsonPath('data.net_equity_override', '-2500.50');

    $this->getJson('/api/v1/crisis-monitoring/profile')->assertOk()->assertJsonPath('data.employees_count', 12)->assertJsonPath('data.going_concern_probable', false);

    $this->putJson('/api/v1/crisis-monitoring/profile', ['legal_form' => 'cooperative'])->assertStatus(422)->assertJsonValidationErrors('legal_form');
    $this->putJson('/api/v1/crisis-monitoring/profile', ['ateco_code' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('ateco_code');
    $this->putJson('/api/v1/crisis-monitoring/profile', ['share_capital' => '-1'])->assertStatus(422)->assertJsonValidationErrors('share_capital');
    $this->putJson('/api/v1/crisis-monitoring/profile', ['ateco_section' => 'Z'])->assertStatus(422)->assertJsonValidationErrors('ateco_section');
});

it('keeps the profile fields that are not sent', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['share_capital' => '100000', 'employees_count' => 5])->assertOk();
    $this->putJson('/api/v1/crisis-monitoring/profile', ['employees_count' => 6])->assertOk()->assertJsonPath('data.share_capital', '100000.00')->assertJsonPath('data.employees_count', 6);
});

it('lets the finance readers read but not write, and shuts out the other roles', function (): void {
    treasuryActor($this, 'accountant_readonly');

    $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk();
    $this->postJson('/api/v1/crisis-monitoring/stress-test', [])->assertOk();
    $this->putJson('/api/v1/crisis-monitoring/profile', ['employees_count' => 1])->assertForbidden();
    $this->postJson('/api/v1/crisis-monitoring/compute')->assertForbidden();
    $this->postJson('/api/v1/crisis-monitoring/subordinated-loans', crisisLoanPayload())->assertForbidden();

    foreach (['member', 'sales'] as $role) {
        treasuryActor($this, $role);

        $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertForbidden();
        $this->postJson('/api/v1/crisis-monitoring/stress-test', [])->assertForbidden();
    }
});
