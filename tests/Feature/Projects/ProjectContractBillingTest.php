<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectRetainerLedgerEntry;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Models\ProjectWipValuationRun;

require_once __DIR__.'/ProjectsSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => projectsSetUp($this));
afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Creates and activates the billing contract of the project as the project manager.
 *
 * @param  array<string, mixed>  $terms
 */
function billingContract(object $test, Project $project, array $terms): array
{
    projectsActAs($test, $test->manager);
    $contract = $test->putJson("/api/v1/projects/{$project->id}/billing-contract", $terms)->assertOk()->json('data');
    $test->postJson("/api/v1/projects/{$project->id}/billing-contract/status", ['billing_status' => 'active', 'expected_version' => $contract['version']])->assertOk();

    return $contract;
}

/**
 * Approved billable hours of the member, at the rate card of the member (billing rate 80).
 */
function billingHours(object $test, Project $project, int $hours, ?string $date = null): string
{
    $id = $test->postJson("/api/v1/projects/{$project->id}/timesheets", ['user_id' => $test->member->id, 'work_date' => $date ?? now()->toDateString(), 'hours_worked' => $hours, 'submit' => true])->assertCreated()->json('data.id');
    $test->postJson("/api/v1/projects/timesheets/{$id}/approve")->assertOk();

    return (string) $id;
}

function billingCost(object $test, Project $project, string $amount, bool $billable = true): ProjectCostEntry
{
    return ProjectCostEntry::factory()->create([
        'tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'project_id' => $project->id,
        'cost_category' => 'material', 'cost_source_type' => 'manual_entry', 'cost_source_id' => (string) Str::uuid7(),
        'quantity' => 1, 'unit_cost' => $amount, 'total_cost' => $amount, 'approval_status' => 'approved', 'is_billable' => $billable,
        'is_invoiced' => false, 'incurred_date' => now()->toDateString(), 'description' => 'Supplier bill',
    ]);
}

/**
 * Drafts, submits and invoices a SAL certificate and returns it.
 *
 * @return array<string, mixed>
 */
function billingInvoicedSal(object $test, Project $project, int $percent): array
{
    $sal = $test->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->subMonth()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => $percent])->assertCreated()->json('data');
    $test->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$sal['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

    return $test->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$sal['id']}/approve-and-invoice", ['tax_rate' => 22, 'approved_by' => 'Mario Rossi'])->assertOk()->assertJsonPath('data.status', 'invoiced')->json('data');
}

function billingNum(mixed $value): float
{
    return round((float) $value, 2);
}

/**
 * @return array<string, mixed>
 */
function billingInvoiceExtra(string $invoiceId): array
{
    return (array) json_decode((string) DB::table('invoices')->where('id', $invoiceId)->value('sdi_extra'), true);
}

test('the billing contract is created, versioned and activated by the project manager', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);

    $this->getJson("/api/v1/projects/{$project->id}/billing-contract")->assertOk()->assertJsonPath('data', null);
    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'contract_total_value' => 0])->assertUnprocessable()->assertJsonValidationErrors(['contract_total_value']);

    $created = $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 5, 'advance_payment_percent' => 10])->assertOk()->json('data');
    expect($created['version'])->toBe(1)
        ->and($created['billing_status'])->toBe('draft')
        ->and($created['fiscal_jurisdiction'])->toBe('IT')
        ->and(billingNum($created['contract_total_value']))->toBe(100000.0)
        ->and($created['customer_id'])->toBe($this->contact->id);

    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 10])->assertConflict();
    $updated = $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 10, 'expected_version' => 1])->assertOk()->json('data');
    expect($updated['version'])->toBe(2);
    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'expected_version' => 1])->assertConflict();

    $this->postJson("/api/v1/projects/{$project->id}/billing-contract/status", ['billing_status' => 'completed'])->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/billing-contract/status", ['billing_status' => 'active'])->assertOk()->assertJsonPath('data.billing_status', 'active');
    $this->postJson("/api/v1/projects/{$project->id}/billing-contract/status", ['billing_status' => 'closed'])->assertOk();
    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress', 'expected_version' => 4])->assertConflict();
});

test('only managers write the billing contract, members read it, and other tenants see nothing', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'time_and_materials']);

    projectsActAs($this, $this->member);
    $this->getJson("/api/v1/projects/{$project->id}/billing-contract")->assertOk()->assertJsonPath('data.billing_model', 'time_and_materials');
    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'hybrid', 'expected_version' => 2])->assertForbidden();
    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 10])->assertForbidden();

    $stranger = projectsUser($this, 'owner', $this->otherTenant);
    projectsActAs($this, $stranger, $this->otherTenant);
    $this->getJson("/api/v1/projects/{$project->id}/billing-contract")->assertNotFound();
    $this->getJson("/api/v1/projects/{$project->id}/sal-certificates")->assertNotFound();
});

test('time and materials: hours at the rate card, costs with markup, non-billable excluded, billed once', function (): void {
    $project = projectsProject($this, ['billing_method' => 'time_and_materials']);
    projectsRateCard($this, $this->member, '40.00', '80.00');
    billingContract($this, $project, ['billing_model' => 'time_and_materials', 'markup_percent' => 10]);

    billingHours($this, $project, 5);
    billingCost($this, $project, '200.00');
    billingCost($this, $project, '999.00', false);

    $preview = $this->getJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials")->assertOk()->json('data');
    expect(billingNum($preview['labour_total']))->toBe(400.0)
        ->and(billingNum($preview['costs_total']))->toBe(200.0)
        ->and(billingNum($preview['markup_total']))->toBe(20.0)
        ->and(billingNum($preview['billable_total']))->toBe(620.0)
        ->and($preview['lines'])->toHaveCount(2);

    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", [])->assertUnprocessable()->assertJsonValidationErrors(['tax_rate']);
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertCreated()->assertJsonPath('data.total_net', '620.00');
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertUnprocessable()->assertJsonValidationErrors(['up_to_date']);

    expect(ProjectTimesheet::query()->where('project_id', $project->id)->whereNull('invoice_id')->count())->toBe(0)
        ->and(ProjectCostEntry::query()->where('project_id', $project->id)->where('is_billable', true)->where('is_invoiced', true)->count())->toBe(1);
});

test('time and materials: the cap cuts the invoice and later drafts bill nothing more', function (): void {
    $project = projectsProject($this, ['billing_method' => 'time_and_materials']);
    projectsRateCard($this, $this->member, '40.00', '80.00');
    billingContract($this, $project, ['billing_model' => 'time_and_materials', 'cap_amount' => 300]);

    billingHours($this, $project, 5);
    billingCost($this, $project, '100.00');

    $preview = $this->getJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials")->assertOk()->json('data');
    expect(billingNum($preview['billable_total']))->toBe(300.0)
        ->and(billingNum($preview['capped_out']))->toBe(200.0);

    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertCreated()->assertJsonPath('data.total_net', '300.00');

    billingHours($this, $project, 2, now()->subDay()->toDateString());
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertUnprocessable();
});

test('time and materials needs an active contract of a model that bills it', function (): void {
    $project = projectsProject($this);
    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertNotFound();

    $this->putJson("/api/v1/projects/{$project->id}/billing-contract", ['billing_model' => 'sal_progress'])->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertConflict();

    $this->postJson("/api/v1/projects/{$project->id}/billing-contract/status", ['billing_status' => 'active'])->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertConflict();
});

test('prepaid retainer: hours burn the bank, the low-balance alert fires once, a top-up re-arms it, overage is billed', function (): void {
    $project = projectsProject($this, ['billing_method' => 'time_and_materials']);
    projectsRateCard($this, $this->member, '40.00', '80.00');
    $contract = billingContract($this, $project, ['billing_model' => 'prepaid_retainer', 'prepaid_hours_allowance' => 10, 'low_balance_alert_threshold_percent' => 25]);

    $this->postJson("/api/v1/projects/{$project->id}/retainer/top-up", [])->assertUnprocessable();

    billingHours($this, $project, 5, now()->subDay()->toDateString());
    billingHours($this, $project, 4);

    $burn = $this->postJson("/api/v1/projects/{$project->id}/retainer/consume", ['up_to_date' => now()->toDateString()])->assertOk()->json('data');
    expect(billingNum($burn['consumed_hours']))->toBe(9.0)
        ->and($burn['timesheet_ids'])->toHaveCount(2);

    $state = $this->getJson("/api/v1/projects/{$project->id}/retainer")->assertOk()->json('data');
    expect(billingNum($state['balance']['remaining_hours']))->toBe(1.0)
        ->and($state['balance']['is_low_balance'])->toBeTrue()
        ->and($state['balance']['unit'])->toBe('hours')
        ->and($state['entries'])->toHaveCount(2)
        ->and(ProjectBillingContract::query()->findOrFail($contract['id'])->low_balance_alerted_at)->not->toBeNull();

    // Burning again takes nothing new and does not alert twice.
    $again = $this->postJson("/api/v1/projects/{$project->id}/retainer/consume", ['up_to_date' => now()->toDateString()])->assertOk()->json('data');
    expect(billingNum($again['consumed_hours']))->toBe(0.0)
        ->and(ProjectRetainerLedgerEntry::query()->where('billing_contract_id', $contract['id'])->where('entry_type', 'consumption')->count())->toBe(2);

    $this->postJson("/api/v1/projects/{$project->id}/retainer/top-up", ['hours' => 10, 'note' => 'Renewal'])->assertCreated()->assertJsonPath('data.entry_type', 'top_up');
    expect(ProjectBillingContract::query()->findOrFail($contract['id'])->low_balance_alerted_at)->toBeNull()
        ->and(billingNum($this->getJson("/api/v1/projects/{$project->id}/retainer")->json('data.balance.remaining_hours')))->toBe(11.0);

    // 12 hours exceed the 11 left: the bank cannot cover them, so they are invoiced as time and materials.
    billingHours($this, $project, 12, now()->subDays(2)->toDateString());
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/time-and-materials", ['tax_rate' => 22])->assertCreated()->assertJsonPath('data.total_net', '960.00');
});

test('the retainer is only for contracts that prepay hours', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'time_and_materials']);

    $this->postJson("/api/v1/projects/{$project->id}/retainer/top-up", ['hours' => 5])->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/retainer/consume", [])->assertConflict();
});

test('SAL: incremental certificates recoup the advance, withhold the retention and link the invoice', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'sal_progress', 'advance_payment_percent' => 10, 'retention_guarantee_percent' => 5]);

    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->toDateString(), 'period_end_date' => now()->subDay()->toDateString(), 'cumulative_completion_percent' => 10])->assertUnprocessable();
    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->subMonth()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 101])->assertUnprocessable();

    $first = $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->subMonth()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 30])->assertCreated()->json('data');
    expect($first['sal_number'])->toBe(1)
        ->and($first['status'])->toBe('draft')
        ->and(billingNum($first['current_period_gross_sal']))->toBe(30000.0)
        ->and(billingNum($first['advance_deduction_amount']))->toBe(3000.0)
        ->and(billingNum($first['guarantee_retention_withheld']))->toBe(1500.0)
        ->and(billingNum($first['invoice_amount']))->toBe(27000.0)
        ->and(billingNum($first['net_billable_amount']))->toBe(25500.0);

    // One certificate at a time, and the progress must advance.
    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->subMonth()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 40])->assertConflict();

    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$first['id']}/submit")->assertOk();
    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$first['id']}/submit")->assertConflict();
    $invoiced = $this->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$first['id']}/approve-and-invoice", ['tax_rate' => 22, 'approved_by' => 'Mario Rossi'])->assertOk()->json('data');

    $invoice = SalesInvoice::query()->findOrFail($invoiced['sales_invoice_id']);
    expect($invoiced['status'])->toBe('invoiced')
        ->and($invoiced['customer_approved_by'])->toBe('Mario Rossi')
        ->and($invoiced['customer_approved_at'])->not->toBeNull()
        ->and(billingNum($invoice->total_net))->toBe(27000.0)
        ->and($invoice->items()->count())->toBe(2)
        ->and($invoice->project_id)->toBe($project->id);

    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$first['id']}/cancel", [])->assertConflict();
    expect(billingNum($this->getJson("/api/v1/projects/{$project->id}/retention")->json('data.balance')))->toBe(1500.0);

    $second = $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 60])->assertCreated()->json('data');
    expect($second['sal_number'])->toBe(2)
        ->and(billingNum($second['previous_completion_percent']))->toBe(30.0)
        ->and(billingNum($second['previous_sal_cumulative_billed']))->toBe(30000.0)
        ->and(billingNum($second['current_period_gross_sal']))->toBe(30000.0)
        ->and(billingNum($second['advance_deduction_amount']))->toBe(3000.0);

    // A cancelled certificate frees its number, its progress and its advance recoupment.
    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates/{$second['id']}/cancel", ['reason' => 'Disputed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $retaken = $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 55])->assertCreated()->json('data');
    expect($retaken['sal_number'])->toBe(2)
        ->and(billingNum($retaken['current_period_gross_sal']))->toBe(25000.0);
    $this->getJson("/api/v1/projects/{$project->id}/sal-certificates")->assertOk()->assertJsonCount(3, 'data');
});

test('SAL: the final certificate recoups the remaining advance and completes the contract', function (): void {
    $project = projectsProject($this);
    $contract = billingContract($this, $project, ['billing_model' => 'sal_progress', 'advance_payment_amount' => 10000]);

    billingInvoicedSal($this, $project, 50);
    $final = billingInvoicedSal($this, $project, 100);

    expect($final['is_final'])->toBeTrue()
        ->and(billingNum($final['current_period_gross_sal']))->toBe(50000.0)
        ->and(billingNum($final['advance_deduction_amount']))->toBe(5000.0)
        ->and(billingNum(ProjectBillingContract::query()->findOrFail($contract['id'])->advance_recouped_amount))->toBe(10000.0)
        ->and(ProjectBillingContract::query()->findOrFail($contract['id'])->billing_status)->toBe('completed');

    $this->postJson("/api/v1/projects/{$project->id}/sal-certificates", ['period_start_date' => now()->toDateString(), 'period_end_date' => now()->toDateString(), 'cumulative_completion_percent' => 100])->assertConflict();
});

test('Italy: a public contract with a CIG withholds 0.50 percent and the invoice carries DatiSAL and DatiContratto', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'sal_progress', 'cig' => 'Z1234567AB', 'cup' => 'J12B21000010001', 'contract_reference' => 'CONTR-2027-01', 'contract_date' => '2027-01-15']);

    $sal = billingInvoicedSal($this, $project, 40);
    expect(billingNum($sal['guarantee_retention_withheld']))->toBe(200.0);

    $extra = billingInvoiceExtra($sal['sales_invoice_id']);
    $general = $extra['FatturaElettronicaBody']['DatiGenerali'];
    expect($general['DatiSAL'][0]['RiferimentoFase'])->toBe(1)
        ->and($general['DatiContratto'][0]['CodiceCIG'])->toBe('Z1234567AB')
        ->and($general['DatiContratto'][0]['CodiceCUP'])->toBe('J12B21000010001')
        ->and($general['DatiContratto'][0]['IdDocumento'])->toBe('CONTR-2027-01')
        ->and(implode(' ', $general['DatiGeneraliDocumento']['Causale']))->toContain('D.Lgs. 36/2023')
        ->and(SalesInvoice::query()->findOrFail($sal['sales_invoice_id'])->jurisdiction)->toBe('IT');
});

test('Italy: a retention stated by the contract wins over the statutory one', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'sal_progress', 'cig' => 'Z1234567AB', 'retention_guarantee_percent' => 4]);

    expect(billingNum(billingInvoicedSal($this, $project, 50)['guarantee_retention_withheld']))->toBe(2000.0);
});

test('Switzerland: Akontorechnung then Schlussabrechnung, retention deducted from the payment, no FatturaPA blocks', function (): void {
    $project = projectsProject($this, ['currency' => 'CHF']);
    billingContract($this, $project, ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 10, 'contract_reference' => 'WERK-17']);
    $this->withHeader('Accept-Language', 'de');

    $partial = billingInvoicedSal($this, $project, 50);
    $final = billingInvoicedSal($this, $project, 100);

    $partialInvoice = SalesInvoice::query()->findOrFail($partial['sales_invoice_id']);
    $finalInvoice = SalesInvoice::query()->findOrFail($final['sales_invoice_id']);
    expect($partialInvoice->jurisdiction)->toBe('CH')
        ->and($partialInvoice->currency)->toBe('CHF')
        ->and($partialInvoice->notes)->toContain('Akontorechnung')->toContain('Garantierückbehalt')->toContain('WERK-17')
        ->and($finalInvoice->notes)->toContain('Schlussabrechnung')
        ->and(billingNum($partial['guarantee_retention_withheld']))->toBe(5000.0)
        ->and(billingNum($partial['net_billable_amount']))->toBe(45000.0)
        ->and(billingInvoiceExtra($partial['sales_invoice_id']))->toBe([])
        ->and(billingNum($this->getJson("/api/v1/projects/{$project->id}/retention")->json('data.balance')))->toBe(10000.0);
});

test('retention release honours the contractual rule and is full or partial', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 10, 'retention_release_rule' => 'on_acceptance']);
    billingInvoicedSal($this, $project, 50);

    expect(billingNum($this->getJson("/api/v1/projects/{$project->id}/retention")->json('data.balance')))->toBe(5000.0);

    $this->postJson("/api/v1/projects/{$project->id}/retention/release", ['amount' => 1000])->assertConflict();
    $this->postJson("/api/v1/projects/{$project->id}/retention/release", ['amount' => 6000, 'acceptance_confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    $this->postJson("/api/v1/projects/{$project->id}/retention/release", ['amount' => 1000, 'acceptance_confirmed' => true])->assertCreated()->assertJsonPath('data.entry_type', 'released');
    expect(billingNum($this->getJson("/api/v1/projects/{$project->id}/retention")->json('data.balance')))->toBe(4000.0);

    $this->postJson("/api/v1/projects/{$project->id}/retention/release", ['acceptance_confirmed' => true])->assertCreated();
    $balance = $this->getJson("/api/v1/projects/{$project->id}/retention")->assertOk()->json('data');
    expect(billingNum($balance['balance']))->toBe(0.0)
        ->and(billingNum($balance['released']))->toBe(5000.0);
    $this->postJson("/api/v1/projects/{$project->id}/retention/release", ['acceptance_confirmed' => true])->assertUnprocessable();
});

test('retention release on warranty expiry and on a completed milestone', function (): void {
    $warranty = projectsProject($this);
    billingContract($this, $warranty, ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 5, 'retention_release_rule' => 'on_warranty_expiry', 'retention_release_date' => now()->addYear()->toDateString()]);
    billingInvoicedSal($this, $warranty, 20);
    $this->postJson("/api/v1/projects/{$warranty->id}/retention/release", [])->assertConflict();
    $this->postJson("/api/v1/projects/{$warranty->id}/retention/release", ['release_date' => now()->addYear()->toDateString()])->assertCreated();

    $milestoned = projectsProject($this);
    billingContract($this, $milestoned, ['billing_model' => 'sal_progress', 'retention_guarantee_percent' => 5, 'retention_release_rule' => 'on_milestone']);
    billingInvoicedSal($this, $milestoned, 20);
    $open = ProjectMilestone::create(['tenant_id' => $milestoned->tenant_id, 'project_id' => $milestoned->id, 'name' => 'Acceptance', 'milestone_date' => now()->toDateString(), 'billing_amount' => 0, 'is_completed' => false]);
    $this->postJson("/api/v1/projects/{$milestoned->id}/retention/release", ['milestone_id' => $open->id])->assertConflict();
    $open->update(['is_completed' => true, 'completed_at' => now()]);
    $this->postJson("/api/v1/projects/{$milestoned->id}/retention/release", ['milestone_id' => $open->id])->assertCreated();
});

test('fixed milestone billing withholds the retention and is refused to other models', function (): void {
    $project = projectsProject($this);
    billingContract($this, $project, ['billing_model' => 'fixed_milestone', 'retention_guarantee_percent' => 10]);
    $milestone = ProjectMilestone::create(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'Phase 1', 'milestone_date' => now()->toDateString(), 'billing_amount' => '20000.00', 'is_completed' => true, 'completed_at' => now()]);

    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/milestones/{$milestone->id}", ['tax_rate' => 22])->assertCreated()->assertJsonPath('data.total_net', '20000.00');
    expect(billingNum($this->getJson("/api/v1/projects/{$project->id}/retention")->json('data.balance')))->toBe(2000.0);
    $this->postJson("/api/v1/projects/{$project->id}/billing/contract/milestones/{$milestone->id}", ['tax_rate' => 22])->assertConflict();

    $other = projectsProject($this);
    billingContract($this, $other, ['billing_model' => 'sal_progress']);
    $stone = ProjectMilestone::create(['tenant_id' => $other->tenant_id, 'project_id' => $other->id, 'name' => 'Phase 1', 'milestone_date' => now()->toDateString(), 'billing_amount' => '1000.00', 'is_completed' => true, 'completed_at' => now()]);
    $this->postJson("/api/v1/projects/{$other->id}/billing/contract/milestones/{$stone->id}", ['tax_rate' => 22])->assertConflict();
});

test('the unbilled summary gathers what is unbilled, the retention and the latest WIP run', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion']);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '50000.00', 'total_revenue_budget' => '100000.00']);
    projectsRateCard($this, $this->member, '40.00', '80.00');
    billingContract($this, $project, ['billing_model' => 'hybrid', 'retention_guarantee_percent' => 5]);
    billingHours($this, $project, 3);

    $summary = $this->getJson("/api/v1/projects/{$project->id}/billing/unbilled-summary")->assertOk()->json('data');
    expect(billingNum($summary['unbilled_time_and_materials']))->toBe(240.0)
        ->and($summary['billing_model'])->toBe('hybrid')
        ->and($summary['latest_wip_run'])->toBeNull()
        ->and(billingNum($summary['retention']['balance']))->toBe(0.0);

    $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => now()->toDateString()])->assertOk();
    expect($this->getJson("/api/v1/projects/{$project->id}/billing/unbilled-summary")->json('data.latest_wip_run.nature'))->toBe('contract_asset');
});

test('WIP cost-to-cost: revenue by the cost ratio, contract asset against billing, GL posted once and the run is idempotent', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion', 'contract_value' => '100000.00']);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '50000.00', 'total_revenue_budget' => '100000.00']);
    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'material', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_cost' => 20000, 'incurred_date' => now()->toDateString()])->assertCreated();

    $date = now()->subDay()->toDateString();
    $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => $date, 'valuation_method' => 'completed_contract'])->assertUnprocessable()->assertJsonValidationErrors(['valuation_method']);

    $result = $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => $date])->assertOk()->json('data');
    $run = $result['runs'][0];
    expect($run['replayed'])->toBeFalse()
        ->and($run['valuation_method'])->toBe('cost_to_cost_percentage_of_completion')
        ->and($run['accounting_standard'])->toBe('OIC 23')
        ->and($run['status'])->toBe('posted')
        ->and(billingNum($run['completion_ratio']))->toBe(0.4)
        ->and(billingNum($run['cumulative_actual_cost_to_date']))->toBe(20000.0)
        ->and(billingNum($run['recognized_revenue_to_date']))->toBe(40000.0)
        ->and(billingNum($run['billed_to_date']))->toBe(0.0)
        ->and(billingNum($run['wip_asset_or_deferred_revenue_diff']))->toBe(40000.0)
        ->and($run['gl_journal_entry_id'])->not->toBeNull()
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '1150'))->toBe('40000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '4000'))->toBe('-40000.00')
        ->and(projectsLedgerBalance($this->tenant->id, $project->id, '5000'))->toBe('20000.00');

    $replay = $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => $date])->assertOk()->json('data.runs.0');
    expect($replay['replayed'])->toBeTrue()
        ->and($replay['id'])->toBe($run['id'])
        ->and(ProjectWipValuationRun::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_document_type', 'revenue_recognition')->count())->toBe(1);

    $this->getJson('/api/v1/projects/wip-valuations?period_end_date='.$date)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.project_id', $project->id);
});

test('WIP billed above the recognised revenue is a contract liability', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion', 'contract_value' => '100000.00']);
    ProjectBudget::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'status' => 'approved', 'total_cost_budget' => '50000.00', 'total_revenue_budget' => '100000.00']);
    projectsActAs($this, $this->manager);
    $this->postJson("/api/v1/projects/{$project->id}/costs", ['cost_category' => 'material', 'cost_source_type' => 'manual_document', 'cost_source_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_cost' => 10000, 'incurred_date' => now()->toDateString()])->assertCreated();
    $milestone = ProjectMilestone::create(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'Advance', 'milestone_date' => now()->toDateString(), 'billing_amount' => '50000.00', 'is_completed' => true, 'completed_at' => now()]);
    $invoiceId = $this->postJson("/api/v1/projects/{$project->id}/billing/milestones/{$milestone->id}", ['tax_rate' => 22])->assertCreated()->json('data.id');
    SalesInvoice::query()->whereKey($invoiceId)->update(['status' => 'posted']);

    $run = $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => now()->toDateString()])->assertOk()->json('data.runs.0');
    expect(billingNum($run['recognized_revenue_to_date']))->toBe(20000.0)
        ->and(billingNum($run['billed_to_date']))->toBe(50000.0)
        ->and(billingNum($run['wip_asset_or_deferred_revenue_diff']))->toBe(-30000.0);
});

test('WIP milestone progress takes the ratio from the completed milestones', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion', 'contract_value' => '100000.00']);
    ProjectMilestone::create(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'Done', 'milestone_date' => now()->toDateString(), 'billing_amount' => '25000.00', 'is_completed' => true, 'completed_at' => now()]);
    ProjectMilestone::create(['tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'Open', 'milestone_date' => now()->addMonth()->toDateString(), 'billing_amount' => '75000.00', 'is_completed' => false]);
    projectsActAs($this, $this->manager);

    $run = $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => now()->toDateString(), 'valuation_method' => 'milestone_progress'])->assertOk()->json('data.runs.0');
    expect($run['valuation_method'])->toBe('milestone_progress')
        ->and(billingNum($run['completion_ratio']))->toBe(0.25)
        ->and(billingNum($run['recognized_revenue_to_date']))->toBe(25000.0);
});

test('WIP completed contract recognises nothing until the project is completed and a project without a method is refused', function (): void {
    $project = projectsProject($this, ['revenue_recognition_method' => 'completed_contract', 'contract_value' => '30000.00']);
    $plain = projectsProject($this);
    projectsActAs($this, $this->manager);

    $this->postJson("/api/v1/projects/{$plain->id}/wip-valuations", ['period_end_date' => now()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['project']);

    $run = $this->postJson("/api/v1/projects/{$project->id}/wip-valuations", ['period_end_date' => now()->toDateString()])->assertOk()->json('data.runs.0');
    expect($run['valuation_method'])->toBe('completed_contract')
        ->and(billingNum($run['recognized_revenue_to_date']))->toBe(0.0)
        ->and(billingNum($run['wip_asset_or_deferred_revenue_diff']))->toBe(0.0);
});

test('the WIP batch values every open project with a method and reports the ones it could not value', function (): void {
    $ok = projectsProject($this, ['revenue_recognition_method' => 'completed_contract', 'contract_value' => '30000.00']);
    $noBudget = projectsProject($this, ['revenue_recognition_method' => 'percentage_of_completion']);
    projectsProject($this);
    projectsActAs($this, $this->manager);

    $result = $this->postJson('/api/v1/projects/wip-valuations', ['period_end_date' => now()->toDateString()])->assertOk()->json('data');
    expect(collect($result['runs'])->pluck('project_id')->all())->toBe([$ok->id])
        ->and(collect($result['skipped'])->pluck('project_id')->all())->toBe([$noBudget->id])
        ->and($result['skipped'][0]['reason'])->not->toBe('');

    $this->postJson('/api/v1/projects/wip-valuations', [])->assertUnprocessable()->assertJsonValidationErrors(['period_end_date']);
    projectsActAs($this, $this->member);
    $this->postJson('/api/v1/projects/wip-valuations', ['period_end_date' => now()->toDateString()])->assertForbidden();
});
