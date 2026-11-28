<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;
use Modules\CrisisMonitoring\Application\Services\DossierPdfBuilder;
use Modules\CrisisMonitoring\Models\AdequateOrganizationalAssetReportCcii;
use Modules\CrisisMonitoring\Models\DscrMonthlyProjection;
use Modules\CrisisMonitoring\Models\SwissCo725SolvencyAssessment;

require_once __DIR__.'/CrisisMonitoringSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    crisisSetUp($this);
});

it('returns both regimes computed from the live cash flows', function (): void {
    crisisScenario($this);

    $data = $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->assertJsonPath('success', true)->json('data');

    expect($data['jurisdiction'])->toBe('IT')
        ->and($data['as_of'])->toBe(now()->toDateString())
        ->and($data['dscr']['treasury']['m6']['value'])->toBe('6.38')
        ->and($data['dscr']['treasury']['m6']['status'])->toBe('green')
        ->and($data['dscr']['treasury']['m6']['label_key'])->toBe('crisisApi.dscr.treasury')
        ->and($data['dscr']['operating']['m6']['value'])->toBe('1.62')
        ->and($data['timeline'])->toHaveCount(12)
        ->and($data['timeline'][0]['operating_inflows'])->toBe('20000.00')
        ->and($data['timeline'][0]['debt_service'])->toBe('10500.00')
        ->and($data['ccii']['indicators'])->toHaveCount(7)
        ->and($data['ccii']['public_creditors'])->toHaveCount(6)
        ->and($data['ccii']['cnc'])->toHaveKeys(['ratio', 'tier_code', 'checklist'])
        ->and($data['swiss'])->toHaveKeys(['overall_status', 'art725', 'art725a', 'art725b', 'subordination'])
        ->and($data['swiss']['art725']['opening_cash'])->toBe('50000.00')
        ->and($data['swiss']['art725']['plan'])->toHaveCount(12);
});

it('puts a red DSCR over six months among the primary triggers of the Italian view', function (): void {
    lpBank($this, '1000.00');
    lpManualItem($this, 'inflow', 'receivable', '2000.00', 10);
    lpManualItem($this, 'outflow', 'debt_principal', '9000.00', 20);

    $ccii = $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->json('data.ccii');

    // (2,000 + 1,000) / 9,000 = 0.33
    expect($ccii['primary_triggers'])->toContain('dscr_6m')->and($ccii['overall_status'])->toBe('red');
});

it('derives the ATECO thresholds of the sector from the profile', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['ateco_code' => '62.01'])->assertOk()->assertJsonPath('data.ateco_section', 'J');

    $ateco = $this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->json('data.ccii.ateco');
    $charges = collect($this->getJson('/api/v1/crisis-monitoring/dashboard')->json('data.ccii.indicators'))->firstWhere('code', 'financial_charges_revenue');

    expect($ateco)->toBe(['code' => '62.01', 'section' => 'J', 'sector_label_key' => 'crisisApi.ateco.J'])
        ->and($charges['threshold'])->toBe('3.00');
});

it('monitors the arrears entered in the profile against the seeded Art. 25-novies thresholds', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['legal_form' => 'partnership', 'employees_count' => 4, 'prior_year_inps_contributions' => '100000', 'inps_overdue_90d_amount' => '40000', 'ader_entrusted_amount' => '250000'])->assertOk();

    $creditors = collect($this->getJson('/api/v1/crisis-monitoring/dashboard')->assertOk()->json('data.ccii.public_creditors'))->keyBy('code');

    expect($creditors['inps']['breached'])->toBeTrue()->and($creditors['inps']['status'])->toBe('red')
        ->and($creditors['ader']['limit'])->toBe('200000.00')
        ->and($creditors['ader']['breached'])->toBeTrue()
        ->and($creditors['payroll']['status'])->toBe('green');
});

it('returns the timeline of the requested horizon and approach', function (): void {
    crisisScenario($this);

    $six = $this->getJson('/api/v1/crisis-monitoring/timeline?horizon=6&approach=operating')->assertOk()->json('data');
    $twelve = $this->getJson('/api/v1/crisis-monitoring/timeline')->assertOk()->json('data');

    expect($six['months'])->toHaveCount(6)->and($six['approach'])->toBe('operating')->and($six['dscr']['value'])->toBe('1.62')
        ->and($twelve['months'])->toHaveCount(12)->and($twelve['approach'])->toBe('treasury');

    $this->getJson('/api/v1/crisis-monitoring/timeline?horizon=7')->assertStatus(422);
});

it('returns the CNC practical test with the checklist', function (): void {
    crisisScenario($this);

    $cnc = $this->getJson('/api/v1/crisis-monitoring/cnc-practical-test')->assertOk()->json('data');

    expect($cnc['tier_code'])->toBeIn(['easily_achievable', 'achievable_with_measures', 'difficult', 'not_feasible'])
        ->and(array_column($cnc['checklist'], 'code'))->toBe(['ateco_configured', 'financial_debt_known', 'tax_social_assessed', 'public_creditor_data', 'cash_flow_positive', 'projection_covers_year']);
});

it('stores the daily snapshot once and replaces it on a second run', function (): void {
    crisisScenario($this);

    $this->postJson('/api/v1/crisis-monitoring/compute')->assertOk()->assertJsonPath('data.dscr.treasury.m6.value', '6.38');
    $this->postJson('/api/v1/crisis-monitoring/compute')->assertOk();

    $first = DscrMonthlyProjection::query()->orderBy('month')->first();

    expect(DscrMonthlyProjection::query()->count())->toBe(12)
        ->and(AdequateOrganizationalAssetReportCcii::query()->count())->toBe(1)
        ->and(SwissCo725SolvencyAssessment::query()->count())->toBe(1)
        ->and((string) $first->dscr_treasury)->toBe('6.38')
        ->and(AdequateOrganizationalAssetReportCcii::query()->first()->trigger)->toBe('manual')
        ->and(AdequateOrganizationalAssetReportCcii::query()->first()->indicators)->toHaveCount(7);
});

it('stores the snapshot of every company from the scheduled command, one company at a time', function (): void {
    $this->artisan('crisis-monitoring:compute', ['--tenant' => $this->tenant->id])->assertExitCode(0);

    treasuryInTenant($this->tenant, function (): void {
        expect(AdequateOrganizationalAssetReportCcii::query()->count())->toBe(1)
            ->and(AdequateOrganizationalAssetReportCcii::query()->first()->trigger)->toBe('scheduled');
    });
    treasuryInTenant($this->otherTenant, function (): void {
        expect(AdequateOrganizationalAssetReportCcii::query()->count())->toBe(0);
    });
});

it('does not show the snapshots or the profile of another company', function (): void {
    $this->putJson('/api/v1/crisis-monitoring/profile', ['legal_form' => 'partnership', 'inps_overdue_90d_amount' => '40000'])->assertOk();
    $this->postJson('/api/v1/crisis-monitoring/compute')->assertOk();

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/crisis-monitoring/profile')->assertOk()->assertJsonPath('data.legal_form', 'corporation')->assertJsonPath('data.inps_overdue_90d_amount', null);

    expect(DscrMonthlyProjection::query()->count())->toBe(0)->and(AdequateOrganizationalAssetReportCcii::query()->count())->toBe(0);
});

it('returns the metadata of the forms', function (): void {
    $metadata = $this->getJson('/api/v1/crisis-monitoring/metadata')->assertOk()->json('data');

    expect($metadata['ateco_sections'])->toHaveCount(19)
        ->and($metadata['ateco_sections'][0])->toBe(['section' => 'A', 'label_key' => 'crisisApi.ateco.A'])
        ->and(array_column($metadata['legal_forms'], 'code'))->toBe(['corporation', 'partnership', 'sole_proprietorship'])
        ->and($metadata['dscr_bands'])->toBe(['red_below' => '1.00', 'yellow_below' => '1.20']);
});

it('downloads the board dossier as a PDF in the requested locale', function (): void {
    crisisScenario($this);

    foreach (['it', 'de'] as $locale) {
        $response = $this->get('/api/v1/crisis-monitoring/dossier/pdf?locale='.$locale)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toContain('adequate-organizational-assets-dossier')
            ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('renders the dossier with the translated texts and the signature block', function (): void {
    crisisScenario($this);
    $inputs = app(CrisisEngine::class)->load($this->tenant, null);
    $snapshot = app(CrisisEngine::class)->evaluate($inputs);

    $html = app(DossierPdfBuilder::class)->html('Acme Srl', $snapshot, [], 'it', now()->toImmutable());

    expect($html)->toContain('Acme Srl')
        ->toContain('art. 2086')
        ->toContain('Collegio sindacale')
        ->not->toContain('crisisApi.');
});
