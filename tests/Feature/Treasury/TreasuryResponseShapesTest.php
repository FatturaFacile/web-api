<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\ReceivableCollection;

require_once __DIR__.'/TreasurySupport.php';

/*
 * Contract of the response shapes the OpenAPI describes for the treasury endpoints that answer with bare resources,
 * plain arrays or the `{success, message, data}` envelope.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(now()->startOfYear()->addMonths(2)->setTime(10, 0));
    treasurySetUp($this);
    $this->bank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $this->bank2 = treasuryBank($this->tenant, 'IT42L1234512345123456789012', $this->accounts['1003']);
    $this->today = now()->toDateString();
    $this->dump = [];
});

test('the treasury responses carry exactly the keys the OpenAPI documents', function (): void {
    $rec = function (string $name, $response) {
        $this->dump[$name] = ['status' => $response->getStatusCode(), 'json' => $response->json()];

        return $response;
    };

    $rec('transfer', $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->bank->id, 'to_bank_account_id' => $this->bank2->id, 'amount' => '100.00', 'transfer_date' => $this->today, 'idempotency_key' => 'T1']));
    $rec('transfer_again', $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->bank->id, 'to_bank_account_id' => $this->bank2->id, 'amount' => '100.00', 'transfer_date' => $this->today, 'idempotency_key' => 'T1']));
    $rec('charge', $this->postJson("/api/v1/treasury/bank-accounts/{$this->bank->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '5.00', 'movement_date' => $this->today, 'idempotency_key' => 'C1']));
    $rec('charge_again', $this->postJson("/api/v1/treasury/bank-accounts/{$this->bank->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '5.00', 'movement_date' => $this->today, 'idempotency_key' => 'C1']));
    $rec('movements', $this->getJson('/api/v1/treasury/movements'));
    $rec('balances', $this->getJson('/api/v1/treasury/balances'));
    $rec('bank_balances', $this->getJson("/api/v1/treasury/bank-accounts/{$this->bank->id}/balances"));
    $rec('position_cash', $this->getJson('/api/v1/treasury/positions/cash'));
    $rec('kpis', $this->getJson('/api/v1/treasury/kpis'));
    $rec('liquidity_forecast', $this->getJson('/api/v1/treasury/liquidity/forecast'));
    $rec('intraday', $this->getJson('/api/v1/treasury/positions/intraday'));
    $rec('buffer', $this->getJson('/api/v1/treasury/positions/buffer'));
    $rec('fx_exposure', $this->getJson('/api/v1/treasury/fx/exposure'));
    $rec('working_capital', $this->getJson('/api/v1/treasury/liquidity/working-capital'));

    $reg = $rec('register', $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'CASSA', 'name' => 'Cassa', 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id, 'opening_balance' => '100.00']))->json('data.id');
    $mv = ['movement_date' => $this->today, 'direction' => 'in', 'amount' => '50.00', 'counter_account_id' => $this->accounts['1090']->id, 'description' => 'x', 'idempotency_key' => 'C-1'];
    $rec('register_movement', $this->postJson("/api/v1/treasury/cash-registers/{$reg}/movements", $mv));
    $rec('register_movement_again', $this->postJson("/api/v1/treasury/cash-registers/{$reg}/movements", $mv));
    $rec('register_movements', $this->getJson("/api/v1/treasury/cash-registers/{$reg}/movements"));

    config(['open_banking.driver' => 'local']);
    $rec('iban', $this->postJson('/api/v1/treasury/iban/check', ['iban' => 'CH9300762011623852957']));
    $consent = $rec('consent', $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957']))->json('data.id');
    $rec('consent_account', $this->getJson("/api/v1/treasury/open-banking/consents/{$consent}/account"));
    $pay = $rec('ob_payment', $this->postJson('/api/v1/treasury/open-banking/payments', ['consent_id' => $consent, 'creditor_iban' => 'DE89 3704 0044 0532 0130 00', 'creditor_name' => 'Fornitore SA', 'amount' => '120.50', 'currency' => 'EUR']))->json('data.id');
    $rec('ob_payment_show', $this->getJson("/api/v1/treasury/open-banking/payments/{$pay}"));

    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente', 'email' => 'c@example.com', 'type' => 'customer', 'country' => 'IT']);
    $col = ReceivableCollection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'expected_amount' => 300, 'collected_amount' => 0, 'due_date' => now()->subDays(40)->toDateString(), 'status' => 'pending']);
    $rec('collections', $this->getJson('/api/v1/treasury/collections'));
    $rec('collections_aging', $this->getJson('/api/v1/treasury/collections/aging'));
    $rec('dunning', $this->getJson('/api/v1/treasury/collections/dunning-readiness'));
    $rec('collection_record', $this->postJson("/api/v1/treasury/collections/{$col->id}/record", ['amount' => 100, 'collection_date' => $this->today]));

    $fc = $rec('forecast_generate', $this->postJson('/api/v1/treasury/cash-forecasts/generate', ['horizon_days' => 30]))->json('id');
    $rec('forecasts', $this->getJson('/api/v1/treasury/cash-forecasts'));
    $rec('forecast_show', $this->getJson("/api/v1/treasury/cash-forecasts/{$fc}"));
    $rec('forecast_deficits', $this->getJson("/api/v1/treasury/cash-forecasts/{$fc}/evaluate-deficits"));
    $rec('scenarios', $this->getJson('/api/v1/treasury/forecast-scenarios'));
    $rec('recurring', $this->getJson('/api/v1/treasury/recurring-items'));

    $st = $rec('statement', $this->postJson('/api/v1/treasury/statements/import', ['bank_account_id' => $this->bank->id, 'statement_date' => $this->today, 'opening_balance' => 10000, 'closing_balance' => 10100, 'lines' => [['booking_date' => $this->today, 'amount' => 100.00, 'bank_reference' => 'TRN-1']]]))->json('id');
    $rc = $rec('auto_reconcile', $this->postJson('/api/v1/treasury/reconciliations/auto-reconcile', ['bank_statement_id' => $st]))->json('id');
    $rec('reconciliations', $this->getJson('/api/v1/treasury/reconciliations'));
    $rec('approve', $this->postJson("/api/v1/treasury/reconciliations/{$rc}/approve"));

    $this->usd = treasuryBank($this->tenant, 'CH9300762011623852957', $this->accounts['1002'], ['currency' => 'USD', 'current_balance' => '500.00']);
    ExchangeRate::query()->create(['source' => 'EUR', 'target' => 'USD', 'rate' => '1.250000', 'effective_date' => $this->today]);
    $rec('fx_exposure_usd', $this->getJson('/api/v1/treasury/fx/exposure'));

    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Più Srl', 'vat_number' => '12345678901', 'fiscal_code' => '12345678901', 'iban' => 'IT60X0306909606100000000001']);
    ContactAddress::create(['contact_id' => $contact->id, 'type' => 'billing', 'street' => 'Via Roma', 'house_number' => '1', 'postal_code' => '20100', 'city' => 'Milano', 'state_canton' => 'MI', 'country' => 'IT']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-1', 'date' => $this->today, 'due_date' => now()->addDays(30)->toDateString(), 'currency' => 'EUR', 'total_net' => '100.00', 'total_tax' => '22.00', 'total_gross' => '122.00', 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $this->tenant->id;
    $invoice->save();
    $this->tenant->update(['vat_number' => '00811720580', 'fiscal_code' => '00811720580']);
    $admin = treasuryActor($this, 'admin');
    $batch = $rec('riba_create', $this->postJson('/api/v1/treasury/riba/batches', ['bank_account_id' => $this->bank->id, 'sia_code' => 'A1B2C', 'batch_reference' => 'RIBA-1', 'receipts' => [['invoice_id' => $invoice->id, 'due_date' => now()->addDays(30)->toDateString()]]]))->json('data.payment_batch_id');
    $this->postJson("/api/v1/treasury/payment-batches/{$batch}/submit-approval")->assertOk();
    treasuryActAs($this, $this->accountant);
    $this->postJson("/api/v1/treasury/payment-batches/{$batch}/approve")->assertOk();
    $rec('riba_show_before', $this->getJson("/api/v1/treasury/riba/batches/{$batch}"));
    $rec('riba_export', $this->postJson("/api/v1/treasury/riba/batches/{$batch}/export"));
    $rec('riba_list', $this->getJson('/api/v1/treasury/riba/batches'));
    $rec('riba_portfolio', $this->getJson('/api/v1/treasury/riba/portfolio'));
    $receipt = $this->dump['riba_export']['json']['data']['receipts'][0]['id'];
    $rec('riba_paid', $this->postJson("/api/v1/treasury/riba/receipts/{$receipt}/paid", ['paid_at' => $this->today]));
    $rec('riba_unpaid', $this->postJson("/api/v1/treasury/riba/receipts/{$receipt}/unpaid", ['reason' => 'Insoluto', 'unpaid_at' => $this->today, 'fee' => '7.50']));
    $rec('bank_delete', $this->deleteJson("/api/v1/treasury/bank-accounts/{$this->usd->id}"));

    $keys = fn (string $name, string $path = ''): array => collect($path === '' ? $this->dump[$name]['json'] : data_get($this->dump[$name]['json'], $path))->keys()->sort()->values()->all();
    $expected = fn (array $keys): array => collect($keys)->sort()->values()->all();
    $movementKeys = ['id', 'movement_type', 'bank_account_id', 'counter_bank_account_id', 'amount', 'currency', 'movement_date', 'description', 'idempotency_key', 'journal_entry_id', 'created_at'];
    $snapshotKeys = ['bank_account_id', 'bank_name', 'iban', 'currency', 'date', 'book_balance', 'statement_balance', 'statement_date', 'snapshot_balance', 'current_balance'];
    $receiptKeys = ['id', 'tenant_id', 'riba_presentation_id', 'payment_batch_id', 'payment_line_id', 'invoice_id', 'payment_id', 'receipt_number', 'due_date', 'amount', 'debtor_name', 'debtor_tax_code', 'debtor_address', 'debtor_postal_code', 'debtor_city', 'debtor_abi', 'debtor_cab', 'status', 'unpaid_at', 'unpaid_reason', 'unpaid_fee', 'created_at', 'updated_at'];
    $paginatorMeta = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

    // The idempotent repetition answers 200 with the same body as the 201.
    expect($this->dump['transfer']['status'])->toBe(201)->and($this->dump['transfer_again']['status'])->toBe(200)
        ->and($this->dump['transfer_again']['json'])->toBe($this->dump['transfer']['json'])
        ->and($keys('transfer', 'data'))->toBe($expected($movementKeys))
        ->and($keys('charge', 'data'))->toBe($expected($movementKeys))
        ->and($this->dump['charge_again']['status'])->toBe(200)
        ->and($this->dump['register_movement_again']['status'])->toBe(200)
        ->and($this->dump['register_movement_again']['json'])->toBe($this->dump['register_movement']['json'])
        ->and($keys('movements'))->toBe($expected(['success', 'message', 'data', 'meta']))
        ->and($keys('movements', 'meta'))->toBe($expected(['total', 'current_page', 'last_page']));

    // Balances, positions, KPIs and the liquidity forecast: enveloped, money as decimal strings.
    expect($keys('balances', 'data.0'))->toBe($expected($snapshotKeys))
        ->and($keys('bank_balances', 'data'))->toBe($expected(['bank_account_id', 'currency', 'from_date', 'to_date', 'opening_balance', 'days', 'at_end']))
        ->and($keys('bank_balances', 'data.days.0'))->toBe($expected(['date', 'debit', 'credit', 'balance']))
        ->and($keys('bank_balances', 'data.at_end'))->toBe($expected($snapshotKeys))
        ->and($keys('position_cash', 'data'))->toBe($expected(['as_of', 'bank_accounts', 'cash_registers', 'totals']))
        ->and($keys('position_cash', 'data.bank_accounts.0'))->toBe($expected(['id', 'bank_name', 'iban', 'currency', 'current_balance', 'available_balance', 'overdraft_limit']))
        ->and($keys('position_cash', 'data.totals.0'))->toBe($expected(['currency', 'bank', 'cash', 'total']))
        ->and($keys('kpis', 'data'))->toBe($expected(['as_of', 'period_from', 'period_days', 'currency', 'sales', 'purchases', 'open_receivables', 'overdue_receivables', 'open_payables', 'overdue_payables', 'cash', 'net_liquidity', 'quick_ratio', 'dso_days', 'dpo_days', 'dio_days', 'cash_conversion_cycle_days', 'includes_inventory']))
        ->and($keys('liquidity_forecast', 'data'))->toBe($expected(['as_of', 'until', 'currency', 'opening_cash', 'minimum_balance', 'scenarios']))
        ->and($keys('liquidity_forecast', 'data.scenarios.0'))->toBe($expected(['scenario', 'name', 'assumptions', 'total_inflows', 'total_outflows', 'closing_balance', 'lowest_balance', 'first_day_below_minimum', 'net_by_source', 'days']))
        ->and($keys('liquidity_forecast', 'data.scenarios.0.assumptions'))->toBe($expected(['collection_delay_days', 'disbursement_acceleration_days', 'sales_growth_factor']));

    // Bare objects (no envelope).
    expect($keys('intraday'))->toBe($expected(['as_of', 'tenant_id', 'company_id', 'base_currency', 'total_ledger_balance_base', 'total_available_balance_base', 'total_overdraft_facility_base', 'positions_by_currency']))
        ->and($keys('intraday', 'positions_by_currency.0'))->toBe($expected(['currency', 'ledger_balance', 'available_balance', 'overdraft_limit', 'account_count', 'accounts']))
        ->and($keys('intraday', 'positions_by_currency.0.accounts.0'))->toBe($expected(['id', 'bank_name', 'iban', 'account_type', 'current_balance', 'available_balance', 'overdraft_limit']))
        ->and($this->dump['fx_exposure']['json'])->toBe([])
        ->and($keys('fx_exposure_usd'))->toBe(['USD'])
        ->and($keys('fx_exposure_usd', 'USD'))->toBe($expected(['id', 'tenant_id', 'company_id', 'currency', 'as_of_date', 'total_cash_holdings', 'open_ar_exposure', 'open_ap_exposure', 'net_exposure', 'base_currency', 'latest_spot_rate', 'var_95_daily', 'created_at', 'updated_at']))
        ->and($keys('collections_aging'))->toBe($expected(['as_of_date', 'tenant_id', 'company_id', 'currency', 'total_open_receivables', 'buckets']))
        ->and($keys('collections_aging', 'buckets'))->toBe($expected(['current', '1_30', '31_60', '61_90', 'over_90']))
        ->and($keys('forecast_deficits'))->toBe($expected(['forecast_id', 'opening_balance', 'inflows', 'outflows', 'closing_balance', 'deficit_detected', 'deficit_date']))
        ->and($keys('collection_record'))->toBe($expected(['id', 'customer_id', 'invoice_id', 'expected_amount', 'collected_amount', 'due_date', 'collection_date', 'payment_method', 'status', 'aging_bucket', 'dunning_level', 'created_at']))
        ->and($keys('collections'))->toBe($expected(['data', 'links', 'meta']))
        ->and($keys('collections', 'meta'))->toBe($expected($paginatorMeta))
        ->and($keys('forecasts', 'meta'))->toBe($expected($paginatorMeta))
        ->and($keys('reconciliations', 'meta'))->toBe($expected($paginatorMeta))
        ->and($keys('reconciliations', 'links'))->toBe($expected(['first', 'last', 'prev', 'next']))
        ->and($keys('auto_reconcile'))->toBe($expected(['id', 'bank_account_id', 'reconciliation_number', 'period_start_date', 'period_end_date', 'bank_statement_balance', 'gl_book_balance', 'difference_amount', 'status', 'approved_by', 'approved_at', 'created_at']));

    // Open banking is enveloped.
    expect($keys('consent_account'))->toBe($expected(['success', 'message', 'data']))
        ->and($keys('consent_account', 'data.balance'))->toBe($expected(['iban', 'currency', 'balance']))
        ->and($keys('ob_payment', 'data'))->toBe($expected(['id', 'creditor_iban', 'amount', 'currency', 'external_payment_id', 'status']));

    // Ri.Ba.
    expect($keys('riba_create', 'data'))->toContain('payment_batch_id', 'sia_code', 'receipts')
        ->and($keys('riba_create', 'data.receipts.0'))->toBe($expected($receiptKeys))
        ->and($keys('riba_show_before', 'data'))->toBe($expected(['id', 'tenant_id', 'payment_batch_id', 'sia_code', 'file_name', 'file_sha256', 'exported_at', 'created_at', 'updated_at', 'receipts', 'payment_batch']))
        ->and($keys('riba_export', 'data'))->toBe($expected(['id', 'tenant_id', 'payment_batch_id', 'sia_code', 'file_name', 'file_sha256', 'exported_at', 'created_at', 'updated_at', 'receipts']))
        ->and($this->dump['riba_export']['json']['data']['receipts'][0]['status'])->toBe('presented')
        ->and($keys('riba_paid', 'data'))->toBe($expected([...$receiptKeys, 'invoice']))
        ->and($this->dump['riba_paid']['json']['data']['status'])->toBe('paid')
        ->and($this->dump['riba_unpaid']['json']['data']['status'])->toBe('unpaid')
        ->and($keys('riba_list', 'data.0'))->toBe($expected(['id', 'batch_reference', 'bank_account_id', 'status', 'execution_date', 'total_amount', 'currency', 'item_count']))
        ->and($keys('riba_portfolio', 'data'))->toBe($expected(['by_status', 'open_receipts']))
        ->and($keys('riba_portfolio', 'data.open_receipts.0'))->toBe($expected(['id', 'payment_batch_id', 'invoice_id', 'receipt_number', 'debtor_name', 'due_date', 'amount', 'status', 'overdue']));
});
