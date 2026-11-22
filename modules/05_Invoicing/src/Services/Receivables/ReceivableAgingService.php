<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Aging of the open receivables, portfolio wide or for one customer, in the buckets of AgingBucketPolicy. Amounts are
 * in one currency (the company currency unless asked otherwise): items in another currency are not mixed in.
 */
final class ReceivableAgingService
{
    public function __construct(private readonly ReceivableItemService $items) {}

    /**
     * @return array{as_of: string, currency: string, available_currencies: list<string>, buckets: list<array{key: string, from: int, to: int|null}>, totals: array<string, string>, total_open: string, total_overdue: string, over_90: string, customers: list<array<string, mixed>>}
     */
    public function aging(string $tenantId, CarbonInterface $today, ?string $customerId = null, ?string $currency = null): array
    {
        $policy = AgingBucketPolicy::forTenant($tenantId);
        $currency ??= (string) (Tenant::query()->whereKey($tenantId)->value('default_currency') ?: 'EUR');
        $this->items->syncTenant($tenantId, $customerId);

        $open = $this->openItems($tenantId, $customerId);
        $currencies = $open->pluck('currency')->unique()->values()->all();
        $open = $open->where('currency', $currency);

        $totals = $policy->emptyTotals();
        $rows = [];

        /** @var ReceivableItem $item */
        foreach ($open as $item) {
            $key = $policy->keyFor($item->daysPastDue($today));
            $totals[$key] = Decimal::add($totals[$key], (string) $item->open_amount);
            $rows[$item->customer_id] ??= ['customer_id' => (string) $item->customer_id, 'buckets' => $policy->emptyTotals(), 'total_open' => '0.00', 'total_overdue' => '0.00', 'items' => 0];
            $rows[$item->customer_id]['buckets'][$key] = Decimal::add($rows[$item->customer_id]['buckets'][$key], (string) $item->open_amount);
            $rows[$item->customer_id]['total_open'] = Decimal::add($rows[$item->customer_id]['total_open'], (string) $item->open_amount);
            $rows[$item->customer_id]['items']++;

            if ($key !== AgingBucketPolicy::Current) {
                $rows[$item->customer_id]['total_overdue'] = Decimal::add($rows[$item->customer_id]['total_overdue'], (string) $item->open_amount);
            }
        }

        $names = Contact::withoutGlobalScopes()->whereKey(array_keys($rows))->get()->mapWithKeys(static fn (Contact $contact): array => [(string) $contact->id => trim((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)))]);

        $customers = collect($rows)->map(function (array $row) use ($names): array {
            $row['customer_name'] = $names->get($row['customer_id']);
            $row['buckets'] = array_map(Decimal::round2(...), $row['buckets']);
            $row['total_open'] = Decimal::round2($row['total_open']);
            $row['total_overdue'] = Decimal::round2($row['total_overdue']);

            return $row;
        })->sortByDesc(static fn (array $row): string => $row['total_overdue'])->values()->all();

        $totals = array_map(Decimal::round2(...), $totals);
        $overdue = array_sum(array_map(static fn (string $key): float => (float) $totals[$key], array_diff($policy->keys(), [AgingBucketPolicy::Current])));
        $over90 = array_sum(array_map(static fn (string $key): float => (float) $totals[$key], $policy->keysOver(90)));

        return [
            'as_of' => $today->toDateString(),
            'currency' => $currency,
            'available_currencies' => $currencies,
            'buckets' => $policy->buckets(),
            'totals' => $totals,
            'total_open' => Decimal::round2((string) array_sum(array_map('floatval', $totals))),
            'total_overdue' => Decimal::round2((string) $overdue),
            'over_90' => Decimal::round2((string) $over90),
            'customers' => $customers,
        ];
    }

    /**
     * @return Collection<int, ReceivableItem>
     */
    public function openItems(string $tenantId, ?string $customerId = null): Collection
    {
        return ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->when($customerId !== null, static fn ($query) => $query->where('customer_id', $customerId))
            ->where('open_amount', '>', 0)
            ->where('status', '!=', ReceivableItem::StatusWrittenOff)
            ->get();
    }
}
