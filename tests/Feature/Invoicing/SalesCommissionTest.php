<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Models\SalesCommission;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesRepresentative;

uses(RefreshDatabase::class);

function commissionMember(Tenant $tenant, string $role = 'owner'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function commissionApi(?User $user = null, ?Tenant $tenant = null): mixed
{
    Sanctum::actingAs($user ?? test()->manager);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

function commissionRep(Tenant $tenant, string $code, string $rate = '5.00', ?string $userId = null): SalesRepresentative
{
    return SalesRepresentative::query()->withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'rep_code' => $code, 'name' => "Agente {$code}", 'default_commission_rate' => $rate, 'user_id' => $userId]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function commissionInvoice(Tenant $tenant, ?string $repId, string $net, array $attributes = []): SalesInvoice
{
    return SalesInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'sales_rep_id' => $repId,
        'status' => 'sent',
        'total_net' => $net,
        'currency' => 'CHF',
        'date' => '2026-03-15',
        ...$attributes,
    ]);
}

function commissionAccount(Tenant $tenant, string $code, string $type): string
{
    return (string) ChartOfAccount::create(['tenant_id' => $tenant->id, 'account_code' => $code, 'name' => "Conto {$code}", 'account_class' => $type, 'account_type' => $type, 'normal_balance' => $type === 'expense' ? 'debit' : 'credit', 'currency' => $tenant->default_currency])->id;
}

/**
 * Accrue March and return the statement of the agent.
 *
 * @return array<string, mixed>
 */
function commissionStatement(SalesRepresentative $rep): array
{
    commissionApi()->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();

    return commissionApi()->postJson('/api/v1/invoicing/commission-statements', ['from' => '2026-03-01', 'to' => '2026-03-31', 'sales_representative_id' => $rep->id])->assertCreated()->json('data');
}

beforeEach(function (): void {
    $this->travelTo('2026-04-02 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Vendite SA', 'legal_name' => 'Vendite SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = commissionMember($this->tenant);
    $this->rep = commissionRep($this->tenant, 'A1');
});

test('accrual reads each issued invoice once, gives negative commission on a credit note and skips what is not issued', function (): void {
    commissionInvoice($this->tenant, $this->rep->id, '1000.00');
    commissionInvoice($this->tenant, $this->rep->id, '200.00', ['invoice_type' => 'credit_note']);
    commissionInvoice($this->tenant, $this->rep->id, '999.00', ['status' => 'draft', 'invoice_number' => 'DRAFT-X1']);
    commissionInvoice($this->tenant, $this->rep->id, '999.00', ['date' => '2026-04-01']);
    commissionInvoice($this->tenant, null, '999.00');

    $first = commissionApi()->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()->json('data');
    $second = commissionApi()->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()->json('data');

    expect($first['accrued'])->toBe(2)->and($second['accrued'])->toBe(0);

    $amounts = SalesCommission::query()->withoutGlobalScopes()->orderByDesc('commission_amount')->pluck('commission_amount')->all();
    expect($amounts)->toBe(['50.00', '-10.00']);
});

test('the agent comes from the sales order, a credit note follows its invoice, and only placeholder drafts are not issued', function (): void {
    $userAgent = commissionMember($this->tenant, 'sales');
    $agentByUser = commissionRep($this->tenant, 'A2', '10.00', $userAgent->id);
    $order = SalesOrder::factory()->create(['tenant_id' => $this->tenant->id, 'sales_rep_id' => $this->rep->id]);
    $userOrder = SalesOrder::factory()->create(['tenant_id' => $this->tenant->id, 'sales_rep_id' => $userAgent->id]);
    $invoice = commissionInvoice($this->tenant, null, '1000.00', ['sales_order_id' => $order->id]);
    commissionInvoice($this->tenant, null, '100.00', ['sales_order_id' => $order->id, 'invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id]);
    commissionInvoice($this->tenant, null, '500.00', ['sales_order_id' => $userOrder->id, 'status' => 'draft', 'invoice_number' => 'FT-2026-0007']);
    commissionInvoice($this->tenant, null, '900.00', ['sales_order_id' => $order->id, 'status' => 'draft', 'invoice_number' => 'DRAFT-ABC']);

    commissionApi()->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()->assertJsonPath('data.accrued', 3);

    $byAgent = SalesCommission::query()->withoutGlobalScopes()->get()->groupBy('sales_representative_id');
    expect($byAgent[$this->rep->id]->pluck('commission_amount')->sort()->values()->all())->toBe(['-5.00', '50.00'])
        ->and($byAgent[$agentByUser->id]->sole()->commission_amount)->toBe('50.00');

    $statement = commissionApi()->postJson('/api/v1/invoicing/commission-statements', ['from' => '2026-03-01', 'to' => '2026-03-31', 'sales_representative_id' => $this->rep->id])->assertCreated()->json('data');
    expect($statement['total_commission'])->toBe('45.00');
});

test('invoices in another currency are counted and left out', function (): void {
    commissionInvoice($this->tenant, $this->rep->id, '1000.00', ['currency' => 'EUR']);

    expect(commissionApi()->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()->json('data'))
        ->toBe(['accrued' => 0, 'skipped_foreign_currency' => 1]);
});

test('targets of an agent and of a team show attainment net of credit notes', function (): void {
    $partner = commissionRep($this->tenant, 'A2');
    commissionInvoice($this->tenant, $this->rep->id, '6000.00');
    commissionInvoice($this->tenant, $this->rep->id, '1000.00', ['invoice_type' => 'credit_note']);
    commissionInvoice($this->tenant, $partner->id, '5000.00');
    $api = commissionApi();
    $team = $api->postJson('/api/v1/invoicing/sales-teams', ['name' => 'Ticino', 'sales_representative_ids' => [$this->rep->id, $partner->id]])->assertCreated()->json('data.id');
    $api->postJson('/api/v1/invoicing/sales-targets', ['sales_representative_id' => $this->rep->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'target_amount' => 10000, 'currency' => 'CHF'])->assertCreated();
    $api->postJson('/api/v1/invoicing/sales-targets', ['sales_team_id' => $team, 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'target_amount' => 8000, 'currency' => 'CHF'])->assertCreated();

    $rows = collect($api->getJson('/api/v1/invoicing/sales-targets/attainment?from=2026-03-01&to=2026-03-31')->assertOk()->json('data'))->keyBy('owner_type');

    expect($rows['agent'])->toMatchArray(['actual_amount' => '5000.00', 'attainment_percent' => '50.00'])
        ->and($rows['team'])->toMatchArray(['actual_amount' => '10000.00', 'attainment_percent' => '125.00', 'owner_name' => 'Ticino']);
});

test('a target belongs to an agent or a team, not both', function (): void {
    $team = commissionApi()->postJson('/api/v1/invoicing/sales-teams', ['name' => 'Ticino'])->assertCreated()->json('data.id');
    $base = ['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'target_amount' => 1000, 'currency' => 'CHF'];

    commissionApi()->postJson('/api/v1/invoicing/sales-targets', $base)->assertUnprocessable();
    commissionApi()->postJson('/api/v1/invoicing/sales-targets', [...$base, 'sales_representative_id' => $this->rep->id, 'sales_team_id' => $team])->assertUnprocessable();
});

test('approving a statement accrues the payable through the ledger', function (): void {
    commissionInvoice($this->tenant, $this->rep->id, '1000.00');
    commissionInvoice($this->tenant, $this->rep->id, '200.00', ['invoice_type' => 'credit_note']);
    $expense = commissionAccount($this->tenant, '6300', 'expense');
    $payable = commissionAccount($this->tenant, '2305', 'liability');
    FiscalPeriod::factory()->create(['tenant_id' => $this->tenant->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    commissionApi()->putJson('/api/v1/invoicing/commission-settings', ['expense_account_id' => $expense, 'payable_account_id' => $payable])->assertOk();

    $statement = commissionStatement($this->rep);
    expect($statement['total_base'])->toBe('800.00')->and($statement['total_commission'])->toBe('40.00');

    $approved = commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/approve")->assertOk()->json('data');
    expect($approved)->toMatchArray(['status' => 'approved', 'ledger_status' => 'posted', 'ledger_error' => null]);

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'sales_commission_statement')->where('source_document_id', $statement['id'])->sole();
    $lines = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->get()->keyBy('account_id');
    expect((float) $lines[$expense]->debit_amount)->toBe(40.0)->and((float) $lines[$payable]->credit_amount)->toBe(40.0);

    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/approve")->assertUnprocessable();
});

test('without accounts or period the statement stays approved and waits, then posts once', function (): void {
    commissionInvoice($this->tenant, $this->rep->id, '1000.00');
    $statement = commissionStatement($this->rep);

    $approved = commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/approve")->assertOk()->json('data');
    expect($approved['status'])->toBe('approved')->and($approved['ledger_status'])->toBe('pending')->and($approved['ledger_error'])->toContain('expense account');

    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/post")->assertUnprocessable();

    commissionApi()->putJson('/api/v1/invoicing/commission-settings', ['expense_account_id' => commissionAccount($this->tenant, '6300', 'expense'), 'payable_account_id' => commissionAccount($this->tenant, '2305', 'liability')])->assertOk();
    FiscalPeriod::factory()->create(['tenant_id' => $this->tenant->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/post")->assertOk()->assertJsonPath('data.ledger_status', 'posted');
    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/post")->assertUnprocessable();
    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $statement['id'])->count())->toBe(1);
});

test('a statement takes each commission once and is paid after approval only', function (): void {
    commissionInvoice($this->tenant, $this->rep->id, '1000.00');
    $statement = commissionStatement($this->rep);

    commissionApi()->postJson('/api/v1/invoicing/commission-statements', ['from' => '2026-03-01', 'to' => '2026-03-31', 'sales_representative_id' => $this->rep->id])->assertUnprocessable();
    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/paid")->assertUnprocessable();

    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/approve")->assertOk();
    commissionApi()->postJson("/api/v1/invoicing/commission-statements/{$statement['id']}/paid")->assertOk()->assertJsonPath('data.status', 'paid');

    expect(SalesCommission::query()->withoutGlobalScopes()->sole()->status)->toBe('paid');
});

test('an agent sees the own statements only and cannot approve, and tenants are isolated', function (): void {
    $agentUser = commissionMember($this->tenant, 'sales');
    $agent = commissionRep($this->tenant, 'A2', '5.00', $agentUser->id);
    commissionInvoice($this->tenant, $this->rep->id, '1000.00');
    commissionInvoice($this->tenant, $agent->id, '500.00');
    $own = commissionStatement($agent);
    $foreign = commissionApi()->postJson('/api/v1/invoicing/commission-statements', ['from' => '2026-03-01', 'to' => '2026-03-31', 'sales_representative_id' => $this->rep->id])->assertCreated()->json('data');

    $listed = commissionApi($agentUser)->getJson('/api/v1/invoicing/commission-statements')->assertOk()->json('data');
    expect(array_column($listed, 'id'))->toBe([$own['id']]);

    commissionApi($agentUser)->postJson("/api/v1/invoicing/commission-statements/{$own['id']}/approve")->assertForbidden();
    commissionApi($agentUser)->postJson('/api/v1/invoicing/commissions/accrue', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertForbidden();

    $otherManager = commissionMember($this->other);
    commissionApi($otherManager, $this->other)->postJson("/api/v1/invoicing/commission-statements/{$foreign['id']}/approve")->assertNotFound();
    expect(commissionApi($otherManager, $this->other)->getJson('/api/v1/invoicing/commission-statements')->assertOk()->json('data'))->toBe([]);
    commissionApi($otherManager, $this->other)->postJson('/api/v1/invoicing/sales-targets', ['sales_representative_id' => $this->rep->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'target_amount' => 1000, 'currency' => 'CHF'])->assertUnprocessable();
    expect(DB::table('sales_targets')->count())->toBe(0);
});
