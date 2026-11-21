<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Quoting\Application\Commands\ConvertQuoteToSalesOrderCommand;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteConvertedToOrder;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Turns an accepted quote into a sales order with zero re-entry: the mandatory lines plus the optional and alternative lines the
 * customer picked, at the agreed net price (every discount of the cascade is already in the unit price, so order and quote totals
 * match to the cent). Idempotent: the quote row is locked and the link is unique, so a double click or a duplicate callback
 * returns the existing order.
 */
final class ConvertQuoteToSalesOrderHandler
{
    public function __construct(
        private readonly SalesOrderServiceInterface $orders,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuoteAuditTrail $audit,
    ) {}

    public function handle(ConvertQuoteToSalesOrderCommand $command): SalesOrder
    {
        $result = DB::transaction(function () use ($command): array {
            $quote = CpqQuote::query()->whereKey($command->quoteId)->lockForUpdate()->firstOrFail();

            if ($quote->converted_sales_order_id !== null) {
                return [SalesOrder::query()->findOrFail($quote->converted_sales_order_id), false, $quote];
            }

            if ($quote->status !== QuoteStatus::Accepted) {
                throw new QuoteException("Quote {$quote->quote_number} is {$quote->status->value}: only an accepted quote can be converted.");
            }

            if ($quote->contact_id === null) {
                throw new QuoteException('The quote has no customer record: link a contact before converting it.');
            }

            $view = $this->snapshots->publicView($quote, (array) $quote->customer_selections);
            $items = [];
            foreach ($view['lines'] as $line) {
                if (! $line['selected'] || ! in_array($line['line_type'], ['product', 'service_fixed', 'service_time_and_material'], true)) {
                    continue;
                }

                $quantity = (string) $line['quantity'];
                $items[] = [
                    'product_id' => $this->productOf($quote, (string) $line['id']),
                    'description' => (string) $line['description'],
                    'quantity' => $quantity,
                    'unit_price' => bccomp($quantity, '0', 4) === 0 ? '0' : bcdiv((string) $line['net'], $quantity, 4),
                    'discount_percentage' => '0',
                    'tax_rate' => (string) $line['tax_rate'],
                    'uom' => (string) ($line['uom'] ?? ''),
                ];
            }

            if ($items === []) {
                throw new QuoteException('The accepted selection has no billable line.');
            }

            $order = $this->orders->createOrder([
                'tenant_id' => (string) $quote->tenant_id,
                'contact_id' => (string) $quote->contact_id,
                'date' => now()->toDateString(),
                'currency' => $quote->currency,
                'payment_terms' => $quote->payment_terms,
                'notes' => "Converted from quote {$quote->quote_number}; signed proposal SHA-256 {$quote->signed_document_hash}",
                'created_by' => $command->userId,
            ], $items);

            $quote->forceFill(['converted_sales_order_id' => (string) $order->id, 'status' => QuoteStatus::Converted->value])->save();
            $this->audit->record($quote, 'converted_to_order', $command->userId, null, ['sales_order_id' => (string) $order->id]);

            return [$order, true, $quote];
        });

        if ($result[1]) {
            event(new QuoteConvertedToOrder($result[2]->id, (string) $result[0]->id));
        }

        return $result[0];
    }

    private function productOf(CpqQuote $quote, string $lineId): ?string
    {
        foreach ((array) ($quote->frozen_snapshot['lines'] ?? []) as $line) {
            if ((string) $line['id'] === $lineId) {
                return $line['product_id'] ?? null;
            }
        }

        return null;
    }
}
