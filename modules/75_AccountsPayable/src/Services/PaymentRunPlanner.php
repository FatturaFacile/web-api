<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AccountsPayable\DTOs\SkontoTerms;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRunItem;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Works out a payment run from the open items that may be paid: which invoices are selected, the cash discount taken on
 * each, the supplier credit notes offset against them (oldest due date first, never more than what the supplier is
 * paid) and the bank transfers, one per creditor IBAN and currency. The planner only calculates; PaymentRunService
 * decides which items are eligible and saves the result.
 *
 * An invoice is selected when it falls due by the cutoff date, or when its Skonto deadline falls on or before the cutoff
 * and the discount is still available on the execution date: it is the last run that earns the discount. The discount is
 * taken whenever it is available at the execution date.
 *
 * @phpstan-type PlanItem array{open_item_id: string, supplier_id: string, kind: string, document_number: string, due_date: string, selection_reason: string, open_amount: string, skonto_percentage: string|null, skonto_deadline: string|null, skonto_amount: string, credit_applied_amount: string, cash_amount: string, credit_used_amount: string, creditor_iban: string|null}
 * @phpstan-type PlanOffset array{debit_item_id: string, credit_item_id: string, amount: string}
 * @phpstan-type PlanTransfer array{supplier_id: string, creditor_iban: string|null, currency: string, gross_amount: string, skonto_amount: string, credit_netted_amount: string, amount: string, items_count: int, item_ids: list<string>, document_numbers: list<string>}
 * @phpstan-type PlanExcluded array{open_item_id: string, document_number: string, supplier_id: string, reason: string}
 * @phpstan-type Plan array{items: list<PlanItem>, offsets: list<PlanOffset>, transfers: list<PlanTransfer>, excluded: list<PlanExcluded>, totals: array{debit_total: string, credit_netted_total: string, skonto_total: string, net_total: string, items_count: int, transfers_count: int}}
 */
final class PaymentRunPlanner
{
    public function __construct(private readonly SkontoCalculator $skonto) {}

    /**
     * @param  Collection<int, ApOpenItem>  $debits  the invoices that may be paid
     * @param  Collection<int, ApOpenItem>  $credits  the credit notes that may be offset
     * @param  array<string, SkontoTerms|null>  $terms  the cash-discount terms by open item id
     * @param  list<PlanExcluded>  $excluded  items already left out by the caller (held, not approved)
     * @param  bool  $selectAll  take every debit given, without testing the due date (a run being rebuilt)
     * @return Plan
     */
    public function plan(
        Collection $debits,
        Collection $credits,
        array $terms,
        CarbonInterface $cutoff,
        CarbonInterface $execution,
        bool $includeSkonto,
        bool $netCredits,
        string $currency,
        array $excluded = [],
        bool $selectAll = false,
    ): array {
        $rows = [];

        foreach ($debits->sortBy(fn (ApOpenItem $item): string => $item->due_date->toDateString().'|'.$item->document_number) as $item) {
            $result = $this->skonto->calculate($terms[(string) $item->id] ?? null, $item->document_date, (string) $item->open_amount, false, $item->isPartiallyPaid(), $execution);
            $skontoTaken = $includeSkonto && $result->eligible;
            $due = $item->due_date->lte($cutoff);
            $lastChance = $skontoTaken && $result->deadlineDate !== null && $result->deadlineDate <= $cutoff->toDateString();

            if (! $selectAll && ! $due && ! $lastChance) {
                continue;
            }

            if ($item->creditor_iban === null || $item->creditor_iban === '') {
                $excluded[] = ['open_item_id' => (string) $item->id, 'document_number' => (string) $item->document_number, 'supplier_id' => (string) $item->supplier_id, 'reason' => 'missing_iban'];

                continue;
            }

            $rows[] = [
                'open_item_id' => (string) $item->id,
                'supplier_id' => (string) $item->supplier_id,
                'kind' => 'invoice',
                'document_number' => (string) $item->document_number,
                'due_date' => $item->due_date->toDateString(),
                'selection_reason' => $skontoTaken ? ApPaymentRunItem::ReasonSkonto : ApPaymentRunItem::ReasonDue,
                'open_amount' => Decimal::round2((string) $item->open_amount),
                'skonto_percentage' => $skontoTaken ? $result->percentage : null,
                'skonto_deadline' => $skontoTaken ? $result->deadlineDate : null,
                'skonto_amount' => $skontoTaken ? $result->savingsAmount : '0.00',
                'credit_applied_amount' => '0.00',
                'cash_amount' => $skontoTaken ? $result->netPayableWithSkonto : Decimal::round2((string) $item->open_amount),
                'credit_used_amount' => '0.00',
                'creditor_iban' => strtoupper((string) $item->creditor_iban),
                'linked' => $item->passive_invoice_id !== null,
            ];
        }

        [$rows, $offsets, $creditRows] = $netCredits ? $this->offset($rows, $credits) : [$rows, [], []];
        $transfers = $this->transfers($rows, $currency);
        $items = array_merge(array_map(static fn (array $row): array => array_diff_key($row, ['linked' => true]), $rows), $creditRows);

        return [
            'items' => $items,
            'offsets' => $offsets,
            'transfers' => $transfers,
            'excluded' => $excluded,
            'totals' => [
                'debit_total' => $this->sum($rows, 'open_amount'),
                'credit_netted_total' => $this->sum($rows, 'credit_applied_amount'),
                'skonto_total' => $this->sum($rows, 'skonto_amount'),
                'net_total' => $this->sum($rows, 'cash_amount'),
                'items_count' => count($items),
                'transfers_count' => count($transfers),
            ],
        ];
    }

    /**
     * Offsets the credit notes of each supplier against its selected invoices, oldest due date first. A credit note that
     * is not in the ledger (a manual entry) is only offset against manual invoices, and the other way round.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, ApOpenItem>  $credits
     * @return array{0: list<array<string, mixed>>, 1: list<PlanOffset>, 2: list<PlanItem>}
     */
    private function offset(array $rows, Collection $credits): array
    {
        $pools = [];

        foreach ($credits->sortBy(fn (ApOpenItem $item): string => $item->due_date->toDateString().'|'.$item->document_number) as $credit) {
            $pools[$credit->supplier_id.'|'.($credit->passive_invoice_id !== null ? 'linked' : 'manual')][] = ['item' => $credit, 'remaining' => Decimal::round2((string) $credit->open_amount), 'used' => '0.00'];
        }

        $offsets = [];

        foreach ($rows as $index => $row) {
            $key = $row['supplier_id'].'|'.($row['linked'] ? 'linked' : 'manual');

            foreach (array_keys($pools[$key] ?? []) as $poolIndex) {
                $payable = $rows[$index]['cash_amount'];

                if (! Decimal::isPositive($payable)) {
                    break;
                }

                $pool = &$pools[$key][$poolIndex];

                if (! Decimal::isPositive($pool['remaining'])) {
                    unset($pool);

                    continue;
                }

                $take = Decimal::compare($pool['remaining'], $payable) < 0 ? $pool['remaining'] : $payable;
                $pool['remaining'] = Decimal::round2(Decimal::sub($pool['remaining'], $take));
                $pool['used'] = Decimal::round2(Decimal::add($pool['used'], $take));
                $rows[$index]['credit_applied_amount'] = Decimal::round2(Decimal::add($rows[$index]['credit_applied_amount'], $take));
                $rows[$index]['cash_amount'] = Decimal::round2(Decimal::sub($rows[$index]['cash_amount'], $take));
                $offsets[] = ['debit_item_id' => $row['open_item_id'], 'credit_item_id' => (string) $pool['item']->id, 'amount' => $take];
                unset($pool);
            }
        }

        $creditRows = [];

        foreach ($pools as $pool) {
            foreach ($pool as $entry) {
                if (! Decimal::isPositive($entry['used'])) {
                    continue;
                }

                /** @var ApOpenItem $credit */
                $credit = $entry['item'];
                $creditRows[] = [
                    'open_item_id' => (string) $credit->id,
                    'supplier_id' => (string) $credit->supplier_id,
                    'kind' => 'credit_note',
                    'document_number' => (string) $credit->document_number,
                    'due_date' => $credit->due_date->toDateString(),
                    'selection_reason' => ApPaymentRunItem::ReasonCreditNote,
                    'open_amount' => Decimal::round2((string) $credit->open_amount),
                    'skonto_percentage' => null,
                    'skonto_deadline' => null,
                    'skonto_amount' => '0.00',
                    'credit_applied_amount' => '0.00',
                    'cash_amount' => '0.00',
                    'credit_used_amount' => $entry['used'],
                    'creditor_iban' => $credit->creditor_iban !== null ? strtoupper((string) $credit->creditor_iban) : null,
                ];
            }
        }

        return [$rows, $offsets, $creditRows];
    }

    /**
     * One transfer per supplier, creditor IBAN and currency: the invoices keep their own allocation.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<PlanTransfer>
     */
    private function transfers(array $rows, string $currency): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['supplier_id'].'|'.$row['creditor_iban'].'|'.$currency;
            $group = $groups[$key] ?? ['supplier_id' => $row['supplier_id'], 'creditor_iban' => $row['creditor_iban'], 'currency' => $currency, 'gross_amount' => '0.00', 'skonto_amount' => '0.00', 'credit_netted_amount' => '0.00', 'amount' => '0.00', 'items_count' => 0, 'item_ids' => [], 'document_numbers' => []];
            $group['gross_amount'] = Decimal::round2(Decimal::add($group['gross_amount'], $row['open_amount']));
            $group['skonto_amount'] = Decimal::round2(Decimal::add($group['skonto_amount'], $row['skonto_amount']));
            $group['credit_netted_amount'] = Decimal::round2(Decimal::add($group['credit_netted_amount'], $row['credit_applied_amount']));
            $group['amount'] = Decimal::round2(Decimal::add($group['amount'], $row['cash_amount']));
            $group['items_count']++;
            $group['item_ids'][] = $row['open_item_id'];
            $group['document_numbers'][] = $row['document_number'];
            $groups[$key] = $group;
        }

        return array_values($groups);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, static fn (string $sum, array $row): string => Decimal::round2(Decimal::add($sum, (string) $row[$field])), '0.00');
    }
}
