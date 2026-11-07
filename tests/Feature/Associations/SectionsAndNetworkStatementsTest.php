<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\StatementMapping;
use Modules\Associations\Services\AssociationLedgerPoster;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->year = (int) now()->year;
});

afterEach(fn () => associationsTearDown());

/**
 * Two sections with their own cost centres and postings, plus a posting outside any section.
 */
function sectionsLedger(object $test): array
{
    openFiscalPeriod(app(TenantContext::class)->getTenant());
    $fees = ChartOfAccount::query()->create(['account_code' => '7010', 'name' => 'Fees', 'account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit']);
    $costs = ChartOfAccount::query()->create(['account_code' => '6100', 'name' => 'Costs', 'account_class' => 'expense', 'account_type' => 'expense', 'normal_balance' => 'debit']);
    StatementMapping::query()->create(['scheme' => 'B', 'line_code' => 'B-R.A.1', 'account_id' => $fees->id]);
    StatementMapping::query()->create(['scheme' => 'B', 'line_code' => 'B-C.A.2', 'account_id' => $costs->id]);
    $youth = CostCenter::query()->create(['code' => 'YOUTH', 'name' => 'Youth teams']);
    $seniors = CostCenter::query()->create(['code' => 'SENIOR', 'name' => 'Senior team']);

    $poster = app(AssociationLedgerPoster::class);
    $today = CarbonImmutable::today();
    $poster->postInflow($fees->id, '600.00', $today, 'cash', 'Youth fees', 'test', (string) Str::uuid7(), ['cost_center_id' => $youth->id], null);
    $poster->postOutflow($costs->id, '150.00', $today, 'cash', 'Youth pitch', 'test', (string) Str::uuid7(), ['cost_center_id' => $youth->id], null);
    $poster->postInflow($fees->id, '400.00', $today, 'cash', 'Senior fees', 'test', (string) Str::uuid7(), ['cost_center_id' => $seniors->id], null);
    $poster->postOutflow($costs->id, '50.00', $today, 'cash', 'Office', 'test', (string) Str::uuid7(), [], null);

    return ['youth' => $youth, 'seniors' => $seniors];
}

test('every section has separate accounts and the parts add up to the consolidated view', function (): void {
    actAs($this, 'admin');
    $centres = sectionsLedger($this);
    $this->postJson('/api/v1/associations/catalogs/sections', ['code' => 'Y', 'name' => 'Youth', 'cost_center_id' => $centres['youth']->id])->assertCreated();
    $this->postJson('/api/v1/associations/catalogs/sections', ['code' => 'S', 'name' => 'Seniors', 'cost_center_id' => $centres['seniors']->id])->assertCreated();
    $this->postJson('/api/v1/associations/catalogs/sections', ['code' => 'S2', 'name' => 'Twice', 'cost_center_id' => $centres['seniors']->id])->assertUnprocessable();

    $report = $this->getJson("/api/v1/associations/sections/{$this->year}/report")->assertOk()->json('data');
    $sections = collect($report['sections'])->keyBy('code');

    expect($sections['Y']['result'])->toBe('450.00')
        ->and($sections['S']['result'])->toBe('400.00')
        ->and($report['not_assigned']['costs'])->toBe('50.00')
        ->and($report['consolidated']['result'])->toBe('800.00');

    $youthReport = $this->getJson("/api/v1/associations/statements/{$this->year}/management-report?cost_center_ids={$centres['youth']->id}")->json('data.totals');
    expect($youthReport['revenues'])->toBe('600.00');
});

test('a parent body reads aggregated statements and compliance flags of consenting affiliates, never people', function (): void {
    $parent = $this->tenant;
    $affiliate = $this->otherTenant;

    actAs($this, 'admin', tenant: $affiliate);
    sectionsLedger($this);
    Member::factory()->create(['health_notes' => 'never shared']);
    $code = $this->getJson('/api/v1/associations/network/code')->json('data.affiliation_code');

    actAs($this, 'admin', tenant: $parent);
    $this->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => $code])->assertCreated();
    $this->getJson("/api/v1/associations/network/statements/{$this->year}")->assertOk()->assertJsonCount(0, 'data.affiliates');

    actAs($this, 'admin', tenant: $affiliate);
    $link = $this->getJson('/api/v1/associations/network/consents')->json('data.0.id');
    $this->postJson("/api/v1/associations/network/consents/{$link}/grant")->assertOk();

    actAs($this, 'admin', tenant: $parent);
    $response = $this->getJson("/api/v1/associations/network/statements/{$this->year}")->assertOk();
    $data = $response->json('data');

    expect($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0]['statement']['result'])->toBe('800.00')
        ->and($data['affiliates'][0]['compliance']['processing_register_kept'])->toBeFalse()
        ->and($data['affiliates'][0]['compliance']['previous_year_statements_filed'])->toBeFalse()
        ->and($data['totals']['revenues'])->toBe('1000.00')
        ->and(array_keys($data['affiliates'][0]))->toBe(['tenant_id', 'name', 'statement', 'compliance'])
        ->and((string) $response->getContent())->not->toContain('never shared')->not->toContain('Youth fees');

    actAs($this, 'admin', tenant: $affiliate);
    $this->postJson("/api/v1/associations/network/consents/{$link}/withdraw")->assertOk();

    actAs($this, 'admin', tenant: $parent);
    $this->getJson("/api/v1/associations/network/statements/{$this->year}")->assertJsonCount(0, 'data.affiliates');

    actAs($this, 'secretary', tenant: $parent);
    $this->getJson("/api/v1/associations/network/statements/{$this->year}")->assertForbidden();
});
