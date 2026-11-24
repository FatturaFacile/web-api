<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Modules\AccountsPayable\Enums\AgingBucket;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Aging of the payables (aging fornitori): what is owed to each supplier by days past the due date, for the cash-flow
 * plan. The buckets (`current`, `1_30`, `31_60`, `61_90`, `over_90`) are the same in the summary, the per-supplier rows and
 * the detail lines. They hold the supplier invoices only; the credit notes are reported next to them and subtracted in
 * the net total. Items in dispute or under block are still debt: they are in the buckets and also shown as held.
 * Amounts are in one currency (the company currency unless asked otherwise): items in another currency are not mixed in.
 *
 * @phpstan-type AgingReport array{as_of: string, currency: string, available_currencies: list<string>, buckets: list<array{key: string, from: int, to: int|null}>, summary: array<string, mixed>, suppliers: list<array<string, mixed>>}
 */
final class PayablesAgingService
{
    public function __construct(private readonly OpenItemSyncService $sync) {}

    /**
     * @return AgingReport
     */
    public function report(string $tenantId, CarbonInterface $asOf, ?string $supplierId = null, ?string $currency = null, bool $withDetail = true): array
    {
        $this->sync->syncTenant($tenantId, $supplierId);
        $currency = strtoupper($currency ?: (string) (Tenant::query()->whereKey($tenantId)->value('default_currency') ?: 'EUR'));

        $open = ApOpenItem::query()
            ->where('tenant_id', $tenantId)
            ->where('open_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->when($supplierId !== null, static fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('due_date')
            ->orderBy('document_number')
            ->get();
        $currencies = $open->pluck('currency')->unique()->sort()->values()->all();
        $open = $open->where('currency', $currency);

        $names = Contact::query()->whereIn('id', $open->pluck('supplier_id')->unique()->all())->get()->keyBy('id');
        $summary = $this->emptyTotals();
        $rows = [];

        /** @var ApOpenItem $item */
        foreach ($open as $item) {
            $days = $item->daysPastDue($asOf);
            $bucket = AgingBucket::forDaysPastDue($days)->value;
            $row = $rows[$item->supplier_id] ?? $this->emptyRow((string) $item->supplier_id, $names->get($item->supplier_id));
            $amount = Decimal::round2((string) $item->open_amount);

            if ($item->isCreditNote()) {
                $row['credit_notes'] = Decimal::round2(Decimal::add($row['credit_notes'], $amount));
                $summary['credit_notes'] = Decimal::round2(Decimal::add($summary['credit_notes'], $amount));
            } else {
                $row['buckets'][$bucket] = Decimal::round2(Decimal::add($row['buckets'][$bucket], $amount));
                $row['total_open'] = Decimal::round2(Decimal::add($row['total_open'], $amount));
                $summary['buckets'][$bucket] = Decimal::round2(Decimal::add($summary['buckets'][$bucket], $amount));
                $summary['total_open'] = Decimal::round2(Decimal::add($summary['total_open'], $amount));

                if ($bucket !== AgingBucket::Current->value) {
                    $row['total_overdue'] = Decimal::round2(Decimal::add($row['total_overdue'], $amount));
                    $summary['total_overdue'] = Decimal::round2(Decimal::add($summary['total_overdue'], $amount));
                }

                if ($item->isHeld()) {
                    $row['held_total'] = Decimal::round2(Decimal::add($row['held_total'], $amount));
                    $summary['held_total'] = Decimal::round2(Decimal::add($summary['held_total'], $amount));
                }
            }

            $row['items_count']++;
            $summary['items_count']++;

            if ($withDetail) {
                $row['items'][] = [
                    'open_item_id' => (string) $item->id,
                    'document_number' => (string) $item->document_number,
                    'kind' => (string) $item->kind,
                    'document_date' => $item->document_date->toDateString(),
                    'due_date' => $item->due_date->toDateString(),
                    'days_past_due' => max(0, $days),
                    'bucket' => $bucket,
                    'amount' => $amount,
                    'status' => (string) $item->status,
                    'on_hold' => $item->isHeld(),
                ];
            }

            $rows[$item->supplier_id] = $row;
        }

        $suppliers = array_values($rows);
        usort($suppliers, static fn (array $a, array $b): int => [-(float) $a['total_overdue'], -(float) $a['total_open'], (string) $a['supplier_name']] <=> [-(float) $b['total_overdue'], -(float) $b['total_open'], (string) $b['supplier_name']]);

        $suppliers = array_map(static fn (array $supplier): array => $supplier + [
            'net_total' => Decimal::round2(Decimal::sub($supplier['total_open'], $supplier['credit_notes'])),
            'over_90' => $supplier['buckets'][AgingBucket::Over90->value],
        ], $suppliers);

        $summary['net_total'] = Decimal::round2(Decimal::sub($summary['total_open'], $summary['credit_notes']));
        $summary['over_90'] = $summary['buckets'][AgingBucket::Over90->value];
        $summary['suppliers_count'] = count($suppliers);

        return [
            'as_of' => $asOf->toDateString(),
            'currency' => $currency,
            'available_currencies' => $currencies,
            'buckets' => $this->buckets(),
            'summary' => $summary,
            'suppliers' => $suppliers,
        ];
    }

    /**
     * @return list<array{key: string, from: int, to: int|null}>
     */
    public function buckets(): array
    {
        return [
            ['key' => AgingBucket::Current->value, 'from' => 0, 'to' => 0],
            ['key' => AgingBucket::Days1To30->value, 'from' => 1, 'to' => 30],
            ['key' => AgingBucket::Days31To60->value, 'from' => 31, 'to' => 60],
            ['key' => AgingBucket::Days61To90->value, 'from' => 61, 'to' => 90],
            ['key' => AgingBucket::Over90->value, 'from' => 91, 'to' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'buckets' => array_fill_keys(AgingBucket::values(), '0.00'),
            'total_open' => '0.00',
            'total_overdue' => '0.00',
            'credit_notes' => '0.00',
            'held_total' => '0.00',
            'items_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(string $supplierId, ?Contact $supplier): array
    {
        return ['supplier_id' => $supplierId, 'supplier_name' => $supplier === null ? null : ($supplier->company_name ?: (trim($supplier->first_name.' '.$supplier->last_name) ?: null)), 'tax_id' => $supplier?->vat_number ?: ($supplier?->fiscal_code ?: null)] + $this->emptyTotals() + ['items' => []];
    }
}
