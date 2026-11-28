<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use Modules\Inventory\Services\ProductStockLedger;

require_once __DIR__.'/BondedWarehousesSupport.php';

uses(RefreshDatabase::class);

const BW_RECONCILIATION = BW_API.'/aeo/reconciliation';

test('a customs book without physical stock raises a critical alert', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);

    $response = $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id])->assertCreated()->assertJsonPath('data.critical', 1);

    $audit = AeoComplianceAudit::query()->where('audit_type', 'Physical_Book_Reconciliation')->sole();

    expect($audit->discrepancy_status->value)->toBe('Critical_Alert_ADM_UDSC')
        ->and($audit->customs_ledger_quantity)->toBe('100.0000')
        ->and($audit->wms_physical_quantity)->toBe('0.0000')
        ->and($audit->discrepancy_quantity)->toBe('-100.0000')
        ->and($audit->findings)->toBe('physical_shortfall')
        ->and($audit->product_id)->toBe($this->product->id)
        ->and($response->json('data.audits'))->toHaveCount(2);
});

test('the physical stock of the WMS that matches the customs book is a match', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);
    app(ProductStockLedger::class)->receive($this->product, (string) $this->warehouse->id, 100.0, []);

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id])->assertCreated()->assertJsonPath('data.critical', 0);

    $audit = AeoComplianceAudit::query()->where('audit_type', 'Physical_Book_Reconciliation')->sole();

    expect($audit->discrepancy_status->value)->toBe('Matched')->and($audit->discrepancy_quantity)->toBe('0.0000');
});

test('the CGU utilization raises a warning above 80 percent and a critical alert above 95 percent', function (int $ceiling, string $status, string $finding): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this, ['cgu_max_ceiling_amount' => $ceiling]);
    bwLot($this, $authorization, 1);

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id])->assertCreated();

    $audit = AeoComplianceAudit::query()->where('audit_type', 'CGU_Threshold_Check')->sole();

    expect($audit->discrepancy_status->value)->toBe($status)->and($audit->findings)->toBe($finding);
})->with([
    'ample plafond' => [1000, 'Matched', 'cgu_utilization_ok'],
    'above 80 percent' => [400, 'Minor_Variance', 'cgu_utilization_warning'],
    'above 95 percent' => [350, 'Critical_Alert_ADM_UDSC', 'cgu_utilization_critical'],
]);

test('the security checks of carriers and suppliers are logged with their AEO status', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id, 'partner_checks' => [
        ['partner_or_carrier_name' => 'Trans AEO SpA', 'partner_aeo_status' => 'AEOF', 'security_seal_verified' => true],
        ['partner_or_carrier_name' => 'Camion Rapido', 'partner_aeo_status' => 'None', 'security_seal_verified' => true],
        ['partner_or_carrier_name' => 'Corriere X', 'partner_aeo_status' => 'AEOS', 'security_seal_verified' => false, 'corrective_action' => 'Re-seal the load'],
    ]])->assertCreated()->assertJsonPath('data.critical', 1);

    $checks = AeoComplianceAudit::query()->where('audit_type', 'Security_Partner_Check')->get()->keyBy('partner_or_carrier_name');

    expect($checks)->toHaveCount(3)
        ->and($checks['Trans AEO SpA']->discrepancy_status->value)->toBe('Matched')
        ->and($checks['Trans AEO SpA']->findings)->toBe('partner_secure')
        ->and($checks['Camion Rapido']->discrepancy_status->value)->toBe('Minor_Variance')
        ->and($checks['Camion Rapido']->findings)->toBe('partner_without_aeo')
        ->and($checks['Corriere X']->discrepancy_status->value)->toBe('Critical_Alert_ADM_UDSC')
        ->and($checks['Corriere X']->security_seal_verified)->toBeFalse()
        ->and($checks['Corriere X']->corrective_action)->toBe('Re-seal the load');
});

test('a usual handling open for more than 30 days is reported', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1, ['entry_date' => '2027-04-01']);
    $this->postJson(BW_API.'/mrn-lots/discharge', bwFifoDischarge($authorization, $this, 10, [
        'discharge_type' => 'UsualHandling_Annex71_03', 'discharge_mrn_number' => null, 'usual_handling_code' => 'Labeling', 'discharge_date' => '2027-05-01',
    ]))->assertCreated();

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id])->assertCreated();

    $audit = AeoComplianceAudit::query()->where('audit_type', 'Usual_Handling_Verification')->sole();

    expect($audit->discrepancy_status->value)->toBe('Minor_Variance')->and($audit->findings)->toBe('usual_handling_open_too_long');
});

test('the dashboard scores the audits and lists the certified authorizations and the log is filterable', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this, ['aeo_next_audit_date' => '2027-06-01']);

    $this->getJson(BW_API.'/aeo/dashboard')->assertOk()->assertJsonPath('data.score', 100.0)->assertJsonPath('data.certified_authorizations.0.audit_overdue', true);

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $authorization->id])->assertCreated();

    $this->getJson(BW_API.'/aeo/dashboard')->assertOk()->assertJsonPath('data.audits_last_90_days', 1)->assertJsonPath('data.matched', 1);
    $this->getJson(BW_API.'/aeo/audits?audit_type=CGU_Threshold_Check')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(BW_API.'/aeo/audits?discrepancy_status=Critical_Alert_ADM_UDSC')->assertOk()->assertJsonCount(0, 'data');
    $this->get(BW_API.'/exports/aeo-audits.csv')->assertOk();
});

test('an IVA warehouse has no customs book to reconcile', function (): void {
    bwSetUp($this);
    $depot = bwAuthorization($this, ['regime_type' => 'VatDepot_Art50Bis']);

    $this->postJson(BW_RECONCILIATION, ['authorization_id' => $depot->id])->assertStatus(422)->assertJsonPath('code', 'regime_not_customs_warehouse');
});
