<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Services\PaymentRunPlanner;
use Modules\AccountsPayable\Services\SkontoCalculator;

const PLAN_TODAY = '2027-06-10';

/**
 * An open item that is not saved: the planner only reads it.
 *
 * @param  array<string, mixed>  $attributes
 */
function plannerItem(string $number, string $amount, string $dueDate, array $attributes = []): ApOpenItem
{
    return new ApOpenItem(array_merge([
        'supplier_id' => 'supplier-a',
        'passive_invoice_id' => 'invoice-'.$number,
        'kind' => 'invoice',
        'document_number' => $number,
        'document_date' => '2027-06-01',
        'due_date' => $dueDate,
        'currency' => 'EUR',
        'original_amount' => $amount,
        'paid_amount' => '0.00',
        'open_amount' => $amount,
        'creditor_iban' => 'IT60X0542811101000000123456',
        'status' => 'open',
    ], $attributes));
}

/**
 * @param  list<ApOpenItem>  $debits
 * @param  list<ApOpenItem>  $credits
 * @param  array<string, SkontoTerms|null>  $terms
 * @return array<string, mixed>
 */
function planRun(array $debits, array $credits = [], string $cutoff = '2027-06-20', array $terms = [], bool $skonto = true, bool $net = true, bool $selectAll = false): array
{
    return (new PaymentRunPlanner(new SkontoCalculator))->plan(
        collect($debits),
        collect($credits),
        $terms,
        CarbonImmutable::parse($cutoff),
        CarbonImmutable::parse(PLAN_TODAY),
        $skonto,
        $net,
        'EUR',
        [],
        $selectAll,
    );
}

it('selects the invoices due by the cutoff date and leaves the later ones', function (): void {
    $due = plannerItem('A-1', '100.00', '2027-06-15');
    $onCutoff = plannerItem('A-2', '200.00', '2027-06-20');
    $later = plannerItem('A-3', '300.00', '2027-06-21');

    $plan = planRun([$due, $onCutoff, $later]);

    expect(array_column($plan['items'], 'document_number'))->toBe(['A-1', 'A-2'])
        ->and($plan['totals'])->toMatchArray(['debit_total' => '300.00', 'net_total' => '300.00', 'skonto_total' => '0.00', 'credit_netted_total' => '0.00', 'items_count' => 2, 'transfers_count' => 1]);
});

it('takes a discount that is still available and pulls in the invoice when its deadline falls by the cutoff', function (): void {
    $discounted = plannerItem('B-1', '500.00', '2027-08-01');
    $notExpiring = plannerItem('B-2', '500.00', '2027-08-01', ['document_date' => '2027-06-09']);
    $terms = [(string) $discounted->id => new SkontoTerms('2.00', 12), (string) $notExpiring->id => new SkontoTerms('2.00', 30)];

    // B-1: invoice of 1 June, 12 days: deadline 13 June, before the cutoff and after the execution date. B-2: deadline 9 July.
    $plan = planRun([$discounted, $notExpiring], terms: $terms);

    expect(array_column($plan['items'], 'document_number'))->toBe(['B-1'])
        ->and($plan['items'][0])->toMatchArray(['selection_reason' => 'skonto', 'skonto_percentage' => '2.00', 'skonto_deadline' => '2027-06-13', 'skonto_amount' => '10.00', 'cash_amount' => '490.00', 'open_amount' => '500.00'])
        ->and($plan['totals'])->toMatchArray(['debit_total' => '500.00', 'skonto_total' => '10.00', 'net_total' => '490.00']);
});

it('takes the discount of an invoice that is due anyway, and not when the option is off or the deadline passed', function (): void {
    $available = plannerItem('C-1', '1000.00', '2027-06-12');
    $expired = plannerItem('C-2', '1000.00', '2027-06-12', ['document_date' => '2027-05-01']);
    $terms = [(string) $available->id => new SkontoTerms('1.50', 14), (string) $expired->id => new SkontoTerms('1.50', 14)];

    $with = planRun([$available, $expired], terms: $terms);
    $without = planRun([$available, $expired], terms: $terms, skonto: false);

    expect(collect($with['items'])->keyBy('document_number')->map(fn (array $row): string => $row['cash_amount'])->all())->toBe(['C-1' => '985.00', 'C-2' => '1000.00'])
        ->and($with['totals']['skonto_total'])->toBe('15.00')
        ->and($without['totals']['skonto_total'])->toBe('0.00')
        ->and($without['totals']['net_total'])->toBe('2000.00');
});

it('never takes a discount on a partly paid invoice', function (): void {
    $partly = plannerItem('D-1', '400.00', '2027-06-12', ['paid_amount' => '100.00', 'original_amount' => '500.00']);

    $plan = planRun([$partly], terms: [(string) $partly->id => new SkontoTerms('2.00', 20)]);

    expect($plan['items'][0])->toMatchArray(['skonto_amount' => '0.00', 'cash_amount' => '400.00', 'selection_reason' => 'due']);
});

it('offsets the credit notes of the supplier, oldest first, and never more than the supplier is paid', function (): void {
    $first = plannerItem('E-1', '300.00', '2027-06-12');
    $second = plannerItem('E-2', '500.00', '2027-06-14');
    $credit = plannerItem('NC-1', '200.00', '2027-06-01', ['kind' => 'credit_note']);
    $bigCredit = plannerItem('NC-2', '900.00', '2027-06-05', ['kind' => 'credit_note']);

    $plan = planRun([$second, $first], [$bigCredit, $credit]);
    $rows = collect($plan['items'])->keyBy('document_number');

    // 800 owed, 1100 of credit: the credit notes cover it all (NC-1 first, then 600 of NC-2) and 300 of NC-2 stays open.
    expect($plan['offsets'])->toHaveCount(3)
        ->and($plan['totals'])->toMatchArray(['debit_total' => '800.00', 'credit_netted_total' => '800.00', 'net_total' => '0.00'])
        ->and($rows['E-1']['credit_applied_amount'])->toBe('300.00')
        ->and($rows['E-2']['credit_applied_amount'])->toBe('500.00')
        ->and($rows['E-1']['cash_amount'])->toBe('0.00')
        ->and($rows['NC-1']['credit_used_amount'])->toBe('200.00')
        ->and($rows['NC-2']['credit_used_amount'])->toBe('600.00')
        ->and($rows['NC-1']['kind'])->toBe('credit_note')
        ->and($plan['transfers'][0])->toMatchArray(['gross_amount' => '800.00', 'credit_netted_amount' => '800.00', 'amount' => '0.00']);
});

it('offsets a smaller credit note against the first invoice and leaves the cash for the rest', function (): void {
    $first = plannerItem('F-1', '300.00', '2027-06-12');
    $second = plannerItem('F-2', '500.00', '2027-06-14');
    $credit = plannerItem('NC-3', '350.00', '2027-06-01', ['kind' => 'credit_note']);

    $plan = planRun([$first, $second], [$credit]);
    $rows = collect($plan['items'])->keyBy('document_number');

    expect($rows['F-1']['credit_applied_amount'])->toBe('300.00')
        ->and($rows['F-2']['credit_applied_amount'])->toBe('50.00')
        ->and($rows['F-2']['cash_amount'])->toBe('450.00')
        ->and($plan['offsets'])->toBe([
            ['debit_item_id' => (string) $first->id, 'credit_item_id' => (string) $credit->id, 'amount' => '300.00'],
            ['debit_item_id' => (string) $second->id, 'credit_item_id' => (string) $credit->id, 'amount' => '50.00'],
        ])
        ->and($plan['totals']['net_total'])->toBe('450.00');
});

it('applies the credit notes after the discount', function (): void {
    $invoice = plannerItem('G-1', '1000.00', '2027-06-12');
    $credit = plannerItem('NC-4', '100.00', '2027-06-01', ['kind' => 'credit_note']);

    $plan = planRun([$invoice], [$credit], terms: [(string) $invoice->id => new SkontoTerms('2.00', 14)]);

    expect($plan['items'][0])->toMatchArray(['skonto_amount' => '20.00', 'credit_applied_amount' => '100.00', 'cash_amount' => '880.00'])
        ->and($plan['totals'])->toMatchArray(['debit_total' => '1000.00', 'skonto_total' => '20.00', 'credit_netted_total' => '100.00', 'net_total' => '880.00']);
});

it('keeps the credit notes of one supplier away from the invoices of another and from a run without netting', function (): void {
    $invoice = plannerItem('H-1', '300.00', '2027-06-12');
    $otherSupplierCredit = plannerItem('NC-5', '100.00', '2027-06-01', ['kind' => 'credit_note', 'supplier_id' => 'supplier-b']);
    $credit = plannerItem('NC-6', '100.00', '2027-06-01', ['kind' => 'credit_note']);

    expect(planRun([$invoice], [$otherSupplierCredit])['totals']['credit_netted_total'])->toBe('0.00')
        ->and(planRun([$invoice], [$credit], net: false)['totals'])->toMatchArray(['credit_netted_total' => '0.00', 'net_total' => '300.00']);
});

it('does not offset a credit note that is not in the ledger against an invoice that is', function (): void {
    $invoice = plannerItem('I-1', '300.00', '2027-06-12');
    $manualCredit = plannerItem('NC-7', '100.00', '2027-06-01', ['kind' => 'credit_note', 'passive_invoice_id' => null]);

    expect(planRun([$invoice], [$manualCredit])['totals']['credit_netted_total'])->toBe('0.00');
});

it('groups the invoices of a supplier by creditor IBAN into one transfer each', function (): void {
    $a1 = plannerItem('J-1', '100.00', '2027-06-12');
    $a2 = plannerItem('J-2', '250.00', '2027-06-13');
    $a3 = plannerItem('J-3', '40.00', '2027-06-14', ['creditor_iban' => 'it84s0542811101000000654321']);
    $b1 = plannerItem('J-4', '60.00', '2027-06-14', ['supplier_id' => 'supplier-b', 'creditor_iban' => 'IT60X0542811101000000123456']);

    $plan = planRun([$a1, $a2, $a3, $b1]);
    $transfers = collect($plan['transfers'])->keyBy(fn (array $transfer): string => $transfer['supplier_id'].'|'.$transfer['creditor_iban']);

    expect($plan['transfers'])->toHaveCount(3)
        ->and($transfers['supplier-a|IT60X0542811101000000123456'])->toMatchArray(['amount' => '350.00', 'item_ids' => [(string) $a1->id, (string) $a2->id], 'document_numbers' => ['J-1', 'J-2'], 'currency' => 'EUR'])
        ->and($transfers['supplier-a|IT84S0542811101000000654321']['amount'])->toBe('40.00')
        ->and($transfers['supplier-b|IT60X0542811101000000123456']['amount'])->toBe('60.00')
        ->and($plan['totals']['transfers_count'])->toBe(3);
});

it('leaves out an invoice without a creditor IBAN and says why', function (): void {
    $withoutIban = plannerItem('K-1', '100.00', '2027-06-12', ['creditor_iban' => null]);
    $withIban = plannerItem('K-2', '50.00', '2027-06-12');

    $plan = planRun([$withoutIban, $withIban]);

    expect(array_column($plan['items'], 'document_number'))->toBe(['K-2'])
        ->and($plan['excluded'])->toBe([['open_item_id' => (string) $withoutIban->id, 'document_number' => 'K-1', 'supplier_id' => 'supplier-a', 'reason' => 'missing_iban']]);
});

it('keeps every invoice given when a run is worked out again', function (): void {
    $later = plannerItem('L-1', '100.00', '2027-12-31');

    expect(planRun([$later])['items'])->toBe([])
        ->and(array_column(planRun([$later], selectAll: true)['items'], 'document_number'))->toBe(['L-1']);
});

it('gives an empty plan when nothing is due', function (): void {
    expect(planRun([]))->toMatchArray(['items' => [], 'offsets' => [], 'transfers' => []])
        ->and(planRun([])['totals'])->toMatchArray(['debit_total' => '0.00', 'net_total' => '0.00', 'items_count' => 0, 'transfers_count' => 0]);
});
