<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\SupplierContract;
use Modules\Rebates\Contracts\RebateLedgerPosterInterface;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateContribution;

require_once __DIR__.'/../Procurement/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

/**
 * In-memory ledger: records what the module asks the general ledger to book.
 */
final class SupplierRebateFakeLedger implements RebateLedgerPosterInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function postAccrual(string $tenantId, string $date, string $amount, string $accrualAccountId, string $incomeAccountId, string $sourceId, string $description, ?string $userId): string
    {
        $id = (string) Str::uuid();
        $this->calls[] = ['kind' => 'accrual', 'id' => $id, 'date' => $date, 'amount' => $amount, 'debit' => $accrualAccountId, 'credit' => $incomeAccountId];

        return $id;
    }

    public function reverse(string $journalEntryId, string $date, string $reason, ?string $userId): string
    {
        $id = (string) Str::uuid();
        $this->calls[] = ['kind' => 'reverse', 'id' => $id, 'of' => $journalEntryId, 'reason' => $reason];

        return $id;
    }

    public function postSetOff(string $tenantId, string $date, string $net, string $tax, string $payablesAccountId, string $accrualAccountId, ?string $vatAccountId, string $sourceId, string $description, ?string $userId): string
    {
        $id = (string) Str::uuid();
        $this->calls[] = ['kind' => 'set_off', 'id' => $id, 'net' => $net, 'tax' => $tax, 'payables' => $payablesAccountId, 'accrual' => $accrualAccountId, 'vat' => $vatAccountId];

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function of(string $kind): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['kind'] === $kind));
    }
}

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->ledger = new SupplierRebateFakeLedger;
    app()->instance(RebateLedgerPosterInterface::class, $this->ledger);
    $this->tenant = Tenant::create(['name' => 'Acquisti '.Str::random(4), 'legal_name' => 'Acquisti Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->owner = User::create(['name' => 'Owner '.Str::random(4), 'email' => Str::random(10).'@supplier-rebates.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $this->owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->owner);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);
    $this->supplier = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Spa', 'type' => 'supplier']);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 */
function spInvoice(string $net, string $date, string $type = 'TD01', array $overrides = []): PassiveInvoice
{
    return PassiveInvoice::create([
        'tenant_id' => test()->tenant->id,
        'supplier_id' => test()->supplier->id,
        'invoice_number' => 'F-'.Str::upper(Str::random(6)),
        'date' => $date,
        'total_amount' => $net,
        'total_net' => $net,
        'total_paid' => 0,
        'document_type' => $type,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function spAgreement(array $overrides = [], bool $activate = true): array
{
    $agreement = test()->postJson('/api/v1/rebates/agreements', [
        'code' => 'PFA-'.Str::upper(Str::random(5)),
        'name' => 'Year-end rebate',
        'direction' => 'purchase',
        'contact_id' => test()->supplier->id,
        'tier_mode' => 'retroactive',
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'accrual_account_id' => (string) Str::uuid(),
        'income_account_id' => (string) Str::uuid(),
        'tiers' => [['threshold_amount' => '10000', 'rate_percent' => '2'], ['threshold_amount' => '50000', 'rate_percent' => '4']],
        ...$overrides,
    ])->assertCreated()->json('data');

    if ($activate) {
        test()->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertOk();
    }

    return $agreement;
}

function spRun(string $agreementId, string $periodEnd): TestResponse
{
    return test()->postJson("/api/v1/rebates/agreements/{$agreementId}/accrual-runs", ['period_end' => $periodEnd]);
}

it('creates a tiered agreement with flat amounts and the programme settings', function (): void {
    $agreement = spAgreement(['tiers' => [['threshold_amount' => '10000', 'rate_percent' => '1', 'flat_amount' => '250']], 'accrual_frequency' => 'quarterly'], false);

    expect($agreement['rebate_type'])->toBe('tiered_volume')
        ->and($agreement['accrual_frequency'])->toBe('quarterly')
        ->and($agreement['version'])->toBe(1)
        ->and($agreement['tiers'][0]['flat_amount'])->toBe('250.00');
});

it('does not require tiers for a fixed bonus but requires the target and the amount', function (): void {
    $payload = ['code' => 'FB-1', 'name' => 'Target bonus', 'direction' => 'purchase', 'contact_id' => $this->supplier->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'rebate_type' => 'fixed_bonus'];

    $this->postJson('/api/v1/rebates/agreements', $payload)->assertUnprocessable()->assertJsonValidationErrors('fixed_bonus_target');

    $created = $this->postJson('/api/v1/rebates/agreements', [...$payload, 'fixed_bonus_target' => '20000', 'fixed_bonus_amount' => '750'])->assertCreated()->json('data');
    expect($created['rebate_type'])->toBe('fixed_bonus')->and($created['tiers'])->toBe([]);
    $this->postJson("/api/v1/rebates/agreements/{$created['id']}/activate")->assertOk();
});

it('refuses a tiered agreement without tiers', function (): void {
    $this->postJson('/api/v1/rebates/agreements', ['code' => 'T-1', 'name' => 'No tiers', 'direction' => 'purchase', 'contact_id' => $this->supplier->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])
        ->assertUnprocessable()->assertJsonValidationErrors('tiers');
});

it('shows the live progress with the distance to the next tier', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');

    $progress = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress?as_of=2027-06-15")->assertOk()->json('data');

    expect($progress['qualifying_volume'])->toBe('20000.00')
        ->and($progress['earned_rebate'])->toBe('400.00')
        ->and($progress['tier']['current_tier']['threshold_amount'])->toBe('10000.00')
        ->and($progress['tier']['next_tier']['threshold_amount'])->toBe('50000.00')
        ->and($progress['tier']['distance_to_next'])->toBe('30000.00')
        ->and($progress['tier']['projected_gain'])->toBe('1600.00')
        ->and($progress['bonus'])->toBeNull()
        ->and($progress['tiers'][0]['max_threshold'])->toBe('50000.00')
        ->and($progress['tiers'][1]['max_threshold'])->toBeNull();
});

it('shows the progress of a fixed target bonus', function (): void {
    $agreement = spAgreement(['rebate_type' => 'fixed_bonus', 'fixed_bonus_target' => '20000', 'fixed_bonus_amount' => '750', 'tiers' => []]);
    spInvoice('15000.00', '2027-02-01');

    $below = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress")->assertOk()->json('data');
    expect($below['tier'])->toBeNull()
        ->and($below['bonus']['reached'])->toBeFalse()
        ->and($below['bonus']['distance_to_target'])->toBe('5000.00')
        ->and($below['bonus']['progress_percent'])->toBe('75.00')
        ->and($below['earned_rebate'])->toBe('0.00');

    spInvoice('6000.00', '2027-03-01');
    $reached = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress")->assertOk()->json('data');
    expect($reached['bonus']['reached'])->toBeTrue()->and($reached['earned_rebate'])->toBe('750.00');
});

it('accrues the contributions that fall due and keeps the later ones planned', function (): void {
    $agreement = spAgreement(['rebate_type' => 'marketing_contribution', 'tiers' => []], false);
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertUnprocessable()->assertJsonValidationErrors('contributions');

    $due = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/contributions", ['kind' => 'co_marketing', 'description' => 'Spring flyer', 'amount' => '1500', 'due_on' => '2027-03-01'])->assertCreated()->json('data');
    $later = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/contributions", ['kind' => 'listing', 'description' => 'Catalogue listing', 'amount' => '800', 'due_on' => '2027-09-01'])->assertCreated()->json('data');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/contributions", ['kind' => 'other', 'description' => 'Bad', 'amount' => '1', 'due_on' => '2027-09-01'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/activate")->assertOk();

    $run = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');
    expect($run['delta_amount'])->toBe('1500.00');
    expect(RebateContribution::find($due['id'])->status)->toBe('accrued')->and(RebateContribution::find($later['id'])->status)->toBe('planned');

    // An accrued contribution is locked; a planned one can still change or go.
    $this->putJson("/api/v1/rebates/contributions/{$due['id']}", ['kind' => 'co_marketing', 'description' => 'Changed', 'amount' => '1', 'due_on' => '2027-03-01'])->assertUnprocessable();
    $this->deleteJson("/api/v1/rebates/contributions/{$due['id']}")->assertUnprocessable();
    $this->deleteJson("/api/v1/rebates/contributions/{$later['id']}")->assertNoContent();

    $progress = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress")->assertOk()->json('data');
    expect($progress['contributions'])->toBe(['planned' => '0.00', 'accrued' => '1500.00', 'settled' => '0.00']);
});

it('runs the periodic accrual idempotently, books it in the ledger and keeps the periods locked in order', function (): void {
    $agreement = spAgreement();
    $first = spInvoice('20000.00', '2027-03-01');

    $march = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');
    expect($march['status'])->toBe('posted')
        ->and($march['period_start'])->toBe('2027-03-01')
        ->and($march['base_amount'])->toBe('20000.00')
        ->and($march['delta_amount'])->toBe('400.00')
        ->and($march['lines'])->toHaveCount(1)
        ->and($march['lines'][0]['invoice_number'])->toBe($first->invoice_number)
        ->and($march['journal_entry_id'])->toBe($this->ledger->of('accrual')[0]['id'])
        ->and($this->ledger->of('accrual')[0]['amount'])->toBe('400.00')
        ->and($this->ledger->of('accrual')[0]['debit'])->toBe($agreement['accrual_account_id'])
        ->and($this->ledger->of('accrual')[0]['credit'])->toBe($agreement['income_account_id']);

    // The same period again returns the same run and books nothing more.
    $again = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');
    expect($again['id'])->toBe($march['id'])->and($this->ledger->of('accrual'))->toHaveCount(1);

    spInvoice('40000.00', '2027-05-10');
    $june = spRun($agreement['id'], '2027-06-30')->assertCreated()->json('data');
    // 60 000 qualify: 4% retroactive is 2 400 and 400 are already accrued.
    expect($june['cumulative_rebate'])->toBe('2400.00')->and($june['delta_amount'])->toBe('2000.00')->and($june['lines'])->toHaveCount(2);

    // Periods before the latest run are locked, and only the latest run can be reversed.
    spRun($agreement['id'], '2027-04-30')->assertUnprocessable()->assertJsonValidationErrors('period_end');
    $this->postJson("/api/v1/rebates/accrual-runs/{$march['id']}/reverse", ['reason' => 'Wrong invoice'])->assertUnprocessable()->assertJsonValidationErrors('run');
    $this->postJson("/api/v1/rebates/accrual-runs/{$june['id']}/reverse", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $reversed = $this->postJson("/api/v1/rebates/accrual-runs/{$june['id']}/reverse", ['reason' => 'Recalculate'])->assertOk()->json('data');
    expect($reversed['status'])->toBe('reversed')->and($reversed['reversal_reason'])->toBe('Recalculate')->and($this->ledger->of('reverse'))->toHaveCount(1)
        ->and($this->ledger->of('reverse')[0]['of'])->toBe($june['journal_entry_id']);

    // Recalculating the period after the reversal accrues the same amount again.
    $recalculated = spRun($agreement['id'], '2027-06-30')->assertCreated()->json('data');
    expect($recalculated['id'])->not->toBe($june['id'])->and($recalculated['delta_amount'])->toBe('2000.00');
    expect(RebateAccrual::query()->where('agreement_id', $agreement['id'])->where('status', 'accrued')->sum('rebate_amount'))->toEqual(2400);
});

it('runs without a ledger entry when the agreement has no accounts', function (): void {
    $agreement = spAgreement(['accrual_account_id' => null, 'income_account_id' => null]);
    spInvoice('20000.00', '2027-03-01');

    $run = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');

    expect($run['delta_amount'])->toBe('400.00')->and($run['journal_entry_id'])->toBeNull()->and($this->ledger->calls)->toBe([]);
});

it('refuses accrual runs for customer agreements and drafts', function (): void {
    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'customer']);
    $sales = spAgreement(['direction' => 'sales', 'contact_id' => $customer->id]);
    spRun($sales['id'], '2027-03-31')->assertUnprocessable()->assertJsonValidationErrors('agreement');

    $draft = spAgreement([], false);
    spRun($draft['id'], '2027-03-31')->assertUnprocessable()->assertJsonValidationErrors('agreement');
});

it('counts credit notes against the qualifying spend of the run', function (): void {
    $agreement = spAgreement();
    spInvoice('30000.00', '2027-02-01');
    $credit = spInvoice('5000.00', '2027-02-20', 'TD04');

    $run = spRun($agreement['id'], '2027-02-28')->assertCreated()->json('data');
    $lines = collect($run['lines']);

    expect($run['base_amount'])->toBe('25000.00')->and($run['delta_amount'])->toBe('500.00')
        ->and($lines->firstWhere('invoice_number', $credit->invoice_number)['net_amount'])->toBe('-5000.00');
});

it('proposes a settlement, walks it through the approval workflow and sets it off against a payable', function (): void {
    $agreement = spAgreement();
    $invoice = spInvoice('20000.00', '2027-03-01');
    $payable = spInvoice('1000.00', '2027-05-01', 'TD01', ['total_amount' => '1000.00']);
    spRun($agreement['id'], '2027-03-31')->assertCreated();

    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'ap_set_off', 'tax_rate' => '22'])->assertUnprocessable()->assertJsonValidationErrors('passive_invoice_id');

    $proposal = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'ap_set_off', 'tax_rate' => '22', 'passive_invoice_id' => $payable->id])
        ->assertCreated()->json('data');

    expect($proposal['status'])->toBe('draft')
        ->and($proposal['version'])->toBe(1)
        ->and($proposal['amount'])->toBe('400.00')
        ->and($proposal['tax_amount'])->toBe('88.00')
        ->and($proposal['gross_amount'])->toBe('488.00')
        ->and($proposal['lines'])->toHaveCount(1)
        ->and($proposal['lines'][0]['line_type'])->toBe('invoice')
        ->and($proposal['lines'][0]['reference'])->toBe($invoice->invoice_number)
        ->and($proposal['lines'][0]['rebate_amount'])->toBe('400.00');
    expect(RebateAccrual::query()->where('agreement_id', $agreement['id'])->value('status'))->toBe('proposed');

    // The accruals belong to the proposal now, so a second proposal finds nothing to settle.
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'credit_note_request', 'tax_rate' => '8.1'])->assertUnprocessable()->assertJsonValidationErrors('agreement');

    $base = "/api/v1/rebates/settlement-proposals/{$proposal['id']}";
    $this->postJson("{$base}/execute", [])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("{$base}/approve", [])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("{$base}/submit", ['version' => 7])->assertUnprocessable()->assertJsonValidationErrors('version');

    $this->postJson("{$base}/submit", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'pending_approval')->assertJsonPath('data.version', 2);
    $this->postJson("{$base}/approve", ['version' => 2])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', (string) $this->owner->id);

    $payables = (string) Str::uuid();
    $vat = (string) Str::uuid();
    $this->postJson("{$base}/execute", ['payables_account_id' => $payables, 'vat_account_id' => $vat, 'executed_on' => '2027-06-15'])
        ->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.set_off_amount', '488.00');

    expect($payable->fresh()->total_paid)->toBe('488.00')
        ->and($payable->fresh()->payment_status)->toBe('partially_paid')
        ->and($this->ledger->of('set_off'))->toHaveCount(1)
        ->and($this->ledger->of('set_off')[0]['net'])->toBe('400.00')
        ->and($this->ledger->of('set_off')[0]['tax'])->toBe('88.00')
        ->and($this->ledger->of('set_off')[0]['payables'])->toBe($payables)
        ->and($this->ledger->of('set_off')[0]['accrual'])->toBe($agreement['accrual_account_id'])
        ->and(RebateAccrual::query()->where('agreement_id', $agreement['id'])->value('status'))->toBe('settled');

    $this->postJson("{$base}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/v1/rebates/settlement-proposals?status=settled')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a set-off that the open balance of the invoice does not cover', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');
    $small = spInvoice('100.00', '2027-05-01');
    $paid = spInvoice('900.00', '2027-05-02', 'TD01', ['total_paid' => '850.00']);
    $credit = spInvoice('900.00', '2027-05-03', 'TD04');
    spRun($agreement['id'], '2027-03-31')->assertCreated();

    foreach ([$small, $paid, $credit] as $invoice) {
        $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'ap_set_off', 'passive_invoice_id' => $invoice->id])
            ->assertUnprocessable()->assertJsonValidationErrors('passive_invoice_id');
    }

    expect(RebateAccrual::query()->where('agreement_id', $agreement['id'])->value('status'))->toBe('accrued');
});

it('cancels a proposal and gives the accruals back', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');
    spRun($agreement['id'], '2027-03-31')->assertCreated();

    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'credit_note_request'])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
    $proposal = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'credit_note_request', 'tax_rate' => '8.1'])->assertCreated()->json('data');
    expect($proposal['tax_amount'])->toBe('32.40')->and($proposal['gross_amount'])->toBe('432.40');

    $this->postJson("/api/v1/rebates/settlement-proposals/{$proposal['id']}/cancel", [])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(RebateAccrual::query()->where('agreement_id', $agreement['id'])->value('status'))->toBe('accrued');

    // The run of the freed accruals can be reversed again.
    $run = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/accrual-runs")->assertOk()->json('data.0');
    $this->postJson("/api/v1/rebates/accrual-runs/{$run['id']}/reverse", ['reason' => 'Test'])->assertOk();
});

it('does not reverse a run whose accruals are in a proposal', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');
    $run = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');
    $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'credit_note_request', 'tax_rate' => '0'])->assertCreated();

    $this->postJson("/api/v1/rebates/accrual-runs/{$run['id']}/reverse", ['reason' => 'Too late'])->assertUnprocessable()->assertJsonValidationErrors('run');
});

it('executes a credit note request without touching the payables', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');
    spRun($agreement['id'], '2027-03-31')->assertCreated();
    $proposal = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'credit_note_request', 'tax_rate' => '8.1'])->assertCreated()->json('data');
    $base = "/api/v1/rebates/settlement-proposals/{$proposal['id']}";

    $this->postJson("{$base}/submit", [])->assertOk();
    $this->postJson("{$base}/approve", [])->assertOk();
    $settled = $this->postJson("{$base}/execute", ['executed_on' => '2027-06-20'])->assertOk()->json('data');

    expect($settled['status'])->toBe('settled')->and($settled['execution_mode'])->toBe('credit_note_request')->and($settled['document_id'])->toBeNull()->and($settled['set_off_amount'])->toBeNull()
        ->and($settled['settled_on'])->toBe('2027-06-20')->and($this->ledger->of('set_off'))->toBe([]);
});

it('splits the fixed bonus settlement into the invoices and a bonus line', function (): void {
    $agreement = spAgreement(['rebate_type' => 'fixed_bonus', 'fixed_bonus_target' => '20000', 'fixed_bonus_amount' => '750', 'tiers' => []]);
    spInvoice('12000.00', '2027-02-01');
    spInvoice('9000.00', '2027-03-01');
    spRun($agreement['id'], '2027-03-31')->assertCreated();

    $proposal = $this->postJson("/api/v1/rebates/agreements/{$agreement['id']}/settlement-proposals", ['execution_mode' => 'charge_invoice', 'tax_rate' => '22'])->assertCreated()->json('data');
    $types = collect($proposal['lines'])->pluck('line_type')->sort()->values()->all();
    $bonus = collect($proposal['lines'])->firstWhere('line_type', 'bonus');

    expect($proposal['amount'])->toBe('750.00')->and($types)->toBe(['bonus', 'invoice', 'invoice'])->and($bonus['rebate_amount'])->toBe('750.00')
        ->and(collect($proposal['lines'])->where('line_type', 'invoice')->sum(fn (array $line): float => (float) $line['rebate_amount']))->toEqual(0);
});

it('restricts the qualifying spend to the invoices of the orders of the blanket agreement', function (): void {
    $procurementSupplier = procSupplier();
    $contractId = (string) $this->postJson('/api/v1/procurement/contracts', [
        'supplier_id' => $procurementSupplier, 'contract_number' => 'BPA-REB', 'title' => 'Blanket', 'contract_type' => 'blanket',
        'total_committed_amount' => 100000, 'starts_at' => '2027-01-01', 'ends_at' => '2027-12-31',
    ])->assertCreated()->json('id');
    SupplierContract::query()->whereKey($contractId)->update(['status' => 'active']);
    $lineId = $this->postJson("/api/v1/procurement/contracts/{$contractId}/lines", ['description' => 'Bolt', 'unit_price' => '1.00', 'price_valid_from' => '2027-01-01'])->assertCreated()->json('data.id');
    $orderId = $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['order_date' => '2027-03-01', 'expected_delivery_date' => '2027-03-10', 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '100']]])->assertCreated()->json('id');

    $agreement = spAgreement(['supplier_contract_id' => $contractId]);
    spInvoice('12000.00', '2027-03-15', 'TD01', ['purchase_order_id' => $orderId]);
    spInvoice('99000.00', '2027-03-16');

    $progress = $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress")->assertOk()->json('data');

    expect($progress['qualifying_volume'])->toBe('12000.00');

    $this->postJson('/api/v1/rebates/agreements', ['code' => 'X-1', 'name' => 'Foreign contract', 'direction' => 'purchase', 'contact_id' => $this->supplier->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'supplier_contract_id' => (string) Str::uuid(), 'tiers' => [['threshold_amount' => '1', 'rate_percent' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('supplier_contract_id');
});

it('detects concurrent edits of an agreement with its version', function (): void {
    $agreement = spAgreement();

    $this->putJson("/api/v1/rebates/agreements/{$agreement['id']}", ['name' => 'Renamed', 'version' => 5])->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->putJson("/api/v1/rebates/agreements/{$agreement['id']}", ['name' => 'Renamed', 'version' => 1])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.version', 2);
    $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}")->assertOk()->assertJsonPath('data.version', 2);
});

it('keeps the agreements and runs of another company out of reach', function (): void {
    $agreement = spAgreement();
    spInvoice('20000.00', '2027-03-01');
    $run = spRun($agreement['id'], '2027-03-31')->assertCreated()->json('data');

    $other = Tenant::create(['name' => 'Altra '.Str::random(4), 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger '.Str::random(4), 'email' => Str::random(10).'@supplier-rebates.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($other);
    Sanctum::actingAs($stranger);
    $this->withHeaders(['X-Tenant-Id' => (string) $other->id]);

    $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/progress")->assertNotFound();
    $this->getJson("/api/v1/rebates/agreements/{$agreement['id']}/accrual-runs")->assertNotFound();
    $this->postJson("/api/v1/rebates/accrual-runs/{$run['id']}/reverse", ['reason' => 'x'])->assertNotFound();
    $this->getJson('/api/v1/rebates/settlement-proposals')->assertOk()->assertJsonCount(0, 'data');
});
