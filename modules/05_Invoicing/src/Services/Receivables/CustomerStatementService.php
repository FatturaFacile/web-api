<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Customer statement (estratto conto): the movements of the customer in a period between an opening and a closing
 * balance, the items still open (with the overdue ones flagged), their aging and the credit position.
 */
final class CustomerStatementService
{
    public function __construct(
        private readonly ReceivableItemService $items,
        private readonly ReceivableAgingService $aging,
        private readonly CreditLimitService $limits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $customerId, string $from, string $to, CarbonInterface $today, ?string $currency = null): array
    {
        $this->items->syncTenant($tenantId, $customerId);
        $customer = Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($customerId);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $currency ??= (string) ($tenant->default_currency ?: 'EUR');

        $ledger = $this->items->ledger($tenantId, $customerId, ['currency' => $currency]);
        $opening = '0.00';
        $movements = [];

        foreach ($ledger['movements'] as $movement) {
            if ($movement['date'] < $from) {
                $opening = $movement['balance'];
            } elseif ($movement['date'] <= $to) {
                $movements[] = $movement;
            }
        }

        $closing = $movements === [] ? $opening : $movements[count($movements) - 1]['balance'];

        $open = ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('currency', $currency)
            ->where('open_amount', '>', 0)
            ->where('status', '!=', ReceivableItem::StatusWrittenOff)
            ->orderBy('due_date')
            ->get()
            ->map(static fn (ReceivableItem $item): array => [
                'receivable_item_id' => (string) $item->id,
                'document_type' => $item->document_type,
                'document_number' => $item->document_number,
                'issue_date' => $item->issue_date->toDateString(),
                'due_date' => $item->due_date->toDateString(),
                'original_amount' => Decimal::round2((string) $item->original_amount),
                'open_amount' => Decimal::round2((string) $item->open_amount),
                'days_past_due' => $item->daysPastDue($today),
                'is_overdue' => $item->daysPastDue($today) > 0,
                'status' => $item->effectiveStatus($today),
            ])->values()->all();

        $overdue = array_reduce($open, static fn (string $sum, array $row): string => $row['is_overdue'] ? Decimal::add($sum, $row['open_amount']) : $sum, '0');
        $status = $this->limits->status($tenantId, $customerId);

        return [
            'tenant' => ['id' => $tenantId, 'name' => (string) ($tenant->legal_name ?: $tenant->name)],
            'customer' => [
                'id' => $customerId,
                'name' => trim((string) ($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name))),
                'email' => $customer->email,
            ],
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'as_of' => $today->toDateString(),
            'opening_balance' => $opening,
            'movements' => $movements,
            'closing_balance' => $closing,
            'open_items' => $open,
            'total_open' => Decimal::round2(array_reduce($open, static fn (string $sum, array $row): string => Decimal::add($sum, $row['open_amount']), '0')),
            'total_overdue' => Decimal::round2($overdue),
            'aging' => $this->aging->aging($tenantId, $today, $customerId, $currency),
            'credit' => [
                'granted_limit' => $status['limit'] !== null ? Decimal::round2((string) $status['limit']->granted_limit) : null,
                'total_exposure' => $status['exposure']['total_exposure'],
                'available_credit' => $status['available_credit'],
            ],
        ];
    }
}
