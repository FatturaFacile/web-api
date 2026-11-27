<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/DownPaymentsSupport.php';

/*
 * Earnest money (caparra confirmatoria) and security deposits: the receipt outside the VAT scope with its virtual stamp duty, the
 * liability posting and the three ways out: imputation to the price with VAT, forfeiture on breach outside the VAT scope, refund
 * single or double (Art. 1385 c.c.).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dpSetUp($this);
});

/**
 * A chain with an earnest money installment of 1000.00 and a security deposit installment of 50.00, both emitted and collected.
 *
 * @return array{chain: array<string, mixed>, earnest: array<string, mixed>, security: array<string, mixed>}
 */
function dpHeldDeposits(object $test, ?string $orderId = null): array
{
    $chain = dpOpenChain($test, ['template' => null, 'installments' => [
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'fixed_gross', 'amount' => '1000.00', 'instrument_type' => 'earnest_money_deposit'],
        ['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'fixed_gross', 'amount' => '50.00', 'instrument_type' => 'security_deposit'],
    ]], $orderId);
    $today = now()->toDateString();
    $earnest = dpEmit($test, $chain['id'], dpInstallment($chain, 1)['id'], ['payment_date' => $today])->assertOk()->json('data.deposit_id');
    $security = dpEmit($test, $chain['id'], dpInstallment($chain, 2)['id'], ['payment_date' => $today, 'payment_account' => 'cash'])->assertOk()->json('data.deposit_id');

    return ['chain' => dpChain($test, $chain['id']), 'earnest' => collect(dpChain($test, $chain['id'])['deposits'])->firstWhere('id', $earnest), 'security' => collect(dpChain($test, $chain['id'])['deposits'])->firstWhere('id', $security)];
}

it('issues the earnest money receipt outside the VAT scope with the virtual stamp duty', function (): void {
    $held = dpHeldDeposits($this);
    $earnest = $held['earnest'];

    expect($earnest['receipt_number'])->toStartWith('CAP-')
        ->and($earnest['deposit_type'])->toBe('earnest_money_caparra_confirmatoria')
        ->and($earnest['vat_exemption_code'])->toBe('N1')
        ->and($earnest['marca_da_bollo_applied'])->toBeTrue()
        ->and($earnest['marca_da_bollo_amount'])->toBe('2.00')
        ->and($earnest['status'])->toBe('held_active')
        ->and($earnest['journal_entry_id'])->not->toBeNull()
        ->and($held['security']['marca_da_bollo_applied'])->toBeFalse()
        ->and($held['security']['payment_account'])->toBe('cash')
        ->and($held['chain']['total_earnest_deposits_held'])->toBe('1000.00')
        ->and($held['chain']['total_security_deposits_held'])->toBe('50.00');

    // Dr bank / Cr deposits received: no VAT account and no revenue account
    expect(dpBalance($this->tenant, '2165'))->toBe('-1050.00')
        ->and(dpBalance($this->tenant, '2200'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '4000'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '6810'))->toBe('2.00')
        ->and(dpBalance($this->tenant, '2240'))->toBe('-2.00');
});

it('applies no stamp duty to a receipt up to 77.47 and none at all to a Swiss company', function (): void {
    $chain = dpOpenChain($this, ['template' => null, 'installments' => [['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'fixed_gross', 'amount' => '77.47', 'instrument_type' => 'earnest_money_deposit']]]);
    $deposit = collect(dpChain($this, dpEmit($this, $chain['id'], dpInstallment($chain, 1)['id'])->assertOk()->json('data.chain.id'))['deposits'])->first();

    expect($deposit['marca_da_bollo_applied'])->toBeFalse()->and($deposit['marca_da_bollo_amount'])->toBe('0.00')->and($deposit['status'])->toBe('issued_unpaid');

    $this->tenant->update(['country' => 'CH']);
    $order = billingOrder($this->tenant, $this->customer);
    $swiss = dpOpenChain($this, ['template' => null, 'installments' => [['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'fixed_gross', 'amount' => '1000.00', 'instrument_type' => 'earnest_money_deposit']]], (string) $order->id);
    $swissDeposit = collect(dpChain($this, dpEmit($this, $swiss['id'], dpInstallment($swiss, 1)['id'], ['payment_date' => now()->toDateString()])->assertOk()->json('data.chain.id'))['deposits'])->first();

    expect($swiss['jurisdiction'])->toBe('CH')
        ->and($swissDeposit['vat_exemption_code'])->toBe('OUT_OF_SCOPE')
        ->and($swissDeposit['marca_da_bollo_applied'])->toBeFalse();
});

it('holds a receipt until its payment is recorded', function (): void {
    $chain = dpOpenChain($this, ['template' => null, 'installments' => [['trigger_type' => 'on_order_confirmation', 'calculation_mode' => 'fixed_gross', 'amount' => '500.00', 'instrument_type' => 'security_deposit']]]);
    $depositId = dpEmit($this, $chain['id'], dpInstallment($chain, 1)['id'])->assertOk()->json('data.deposit_id');

    expect(dpBalance($this->tenant, '2165'))->toBe('0.00')->and(dpChain($this, $chain['id'])['total_security_deposits_held'])->toBe('0.00');

    $url = "/api/v1/down-payment-chains/{$chain['id']}/deposits/{$depositId}/confirm-payment";
    $this->postJson($url, ['paid_at' => now()->toDateString(), 'payment_account' => 'bank'])->assertOk()->assertJsonPath('data.status', 'held_active');
    $this->postJson($url, ['paid_at' => now()->toDateString()])->assertUnprocessable()->assertJsonPath('code', 'deposit_already_collected');

    expect(dpBalance($this->tenant, '2165'))->toBe('-500.00')
        ->and(dpInstallment(dpChain($this, $chain['id']), 1)['status'])->toBe('paid');
});

it('forfeits the earnest money on breach as income outside the VAT scope', function (): void {
    $held = dpHeldDeposits($this);
    $url = "/api/v1/down-payment-chains/{$held['chain']['id']}/deposits/{$held['earnest']['id']}";

    $preview = $this->postJson($url.'/preview-resolution', ['resolution' => 'forfeit_breach'])->assertOk()->json('data');
    expect($preview['vat_applies'])->toBeFalse()->and($preview['lines'][1]['account'])->toBe('forfeited_deposits_income');

    $chain = $this->postJson($url.'/resolve', ['resolution' => 'forfeit_breach'])->assertOk()->json('data');
    $deposit = collect($chain['deposits'])->firstWhere('id', $held['earnest']['id']);

    expect($deposit['status'])->toBe('forfeited_on_breach')
        ->and($deposit['resolution'])->toBe('forfeit_breach')
        ->and($chain['status'])->toBe('forfeited')
        ->and($chain['total_earnest_deposits_held'])->toBe('0.00')
        ->and(dpBalance($this->tenant, '2165'))->toBe('-50.00')
        ->and(dpBalance($this->tenant, '4185'))->toBe('-1000.00')
        ->and(dpBalance($this->tenant, '2200'))->toBe('0.00');

    $this->postJson($url.'/resolve', ['resolution' => 'forfeit_breach'])->assertUnprocessable()->assertJsonPath('code', 'deposit_not_held');
});

it('refunds the earnest money in double with the penalty of Art. 1385', function (): void {
    $held = dpHeldDeposits($this);
    $url = "/api/v1/down-payment-chains/{$held['chain']['id']}/deposits/{$held['earnest']['id']}/resolve";

    $this->postJson($url, ['resolution' => 'refund'])->assertUnprocessable()->assertJsonValidationErrors(['refund_mode']);
    $chain = $this->postJson($url, ['resolution' => 'refund', 'refund_mode' => 'double'])->assertOk()->json('data');
    $deposit = collect($chain['deposits'])->firstWhere('id', $held['earnest']['id']);

    expect($deposit['status'])->toBe('refunded_double_art1385')
        ->and($deposit['penalty_amount'])->toBe('1000.00')
        ->and(dpBalance($this->tenant, '2165'))->toBe('-50.00')
        ->and(dpBalance($this->tenant, '6830'))->toBe('1000.00')
        // The bank paid the earnest money back twice: 1000 collected, 2000 refunded
        ->and(dpBalance($this->tenant, '1020'))->toBe('-1000.00');
});

it('refunds a security deposit once and refuses a double refund of it', function (): void {
    $held = dpHeldDeposits($this);
    $url = "/api/v1/down-payment-chains/{$held['chain']['id']}/deposits/{$held['security']['id']}/resolve";

    $this->postJson($url, ['resolution' => 'refund', 'refund_mode' => 'double'])->assertUnprocessable()->assertJsonPath('code', 'double_refund_only_for_earnest_money');
    $chain = $this->postJson($url, ['resolution' => 'refund', 'refund_mode' => 'single', 'payment_account' => 'cash'])->assertOk()->json('data');

    expect(collect($chain['deposits'])->firstWhere('id', $held['security']['id'])['status'])->toBe('refunded_single')
        ->and($chain['total_security_deposits_held'])->toBe('0.00')
        ->and(dpBalance($this->tenant, '2165'))->toBe('-1000.00');
});

it('imputes the earnest money to the price as an advance invoice settled with the deposit', function (): void {
    $held = dpHeldDeposits($this);
    $url = "/api/v1/down-payment-chains/{$held['chain']['id']}/deposits/{$held['earnest']['id']}";

    $preview = $this->postJson($url.'/preview-resolution', ['resolution' => 'impute_to_price'])->assertOk()->json('data');
    expect($preview['vat_applies'])->toBeTrue()
        ->and(collect($preview['lines'])->firstWhere('account', 'customer_advances')['credit'])->toBe('833.35');

    $chain = $this->postJson($url.'/resolve', ['resolution' => 'impute_to_price'])->assertOk()->json('data');
    $deposit = collect($chain['deposits'])->firstWhere('id', $held['earnest']['id']);
    $advance = dpInvoice($deposit['converted_invoice_id']);

    expect($deposit['status'])->toBe('converted_to_advance')
        ->and($advance->is_deposit)->toBeTrue()
        ->and((string) $advance->total_gross)->toBe('1000.00')
        ->and($advance->payment_status)->toBe('paid')
        ->and($chain['total_advance_invoiced_gross'])->toBe('1000.00')
        ->and($chain['total_earnest_deposits_held'])->toBe('0.00')
        ->and(dpBalance($this->tenant, '2165'))->toBe('-50.00')
        ->and(dpBalance($this->tenant, '2160'))->toBe('-833.35');

    billingAssertBalanced($advance->id);
});

it('keeps the final settlement closed while earnest money is held and imputes it on request', function (): void {
    $held = dpHeldDeposits($this);
    $chainId = $held['chain']['id'];

    $preview = $this->postJson("/api/v1/down-payment-chains/{$chainId}/preview-final-settlement")->assertOk()->json('data');
    expect($preview['blockers'])->toBe([['code' => 'held_earnest_money', 'id' => $held['earnest']['id']]]);

    $this->postJson("/api/v1/down-payment-chains/{$chainId}/generate-final-settlement")->assertUnprocessable()->assertJsonPath('code', 'held_earnest_money');

    $response = $this->postJson("/api/v1/down-payment-chains/{$chainId}/generate-final-settlement", ['impute_deposit_ids' => [$held['earnest']['id']]])->assertCreated();
    $invoice = dpInvoice($response->json('data.invoice_id'));

    // The imputed advance is deducted by the very invoice that settles the chain; the security deposit stays held
    expect((string) $invoice->total_gross)->toBe('440.00')
        ->and($response->json('data.chain.status'))->toBe('settled')
        ->and(dpBalance($this->tenant, '2160'))->toBe('0.00')
        ->and(dpBalance($this->tenant, '2165'))->toBe('-50.00');

    billingAssertBalanced($invoice->id);
});

it('lets only finance resolve a deposit', function (): void {
    $held = dpHeldDeposits($this);
    billingActor($this, 'sales_manager');

    $this->postJson("/api/v1/down-payment-chains/{$held['chain']['id']}/deposits/{$held['earnest']['id']}/resolve", ['resolution' => 'forfeit_breach'])->assertForbidden();
});
