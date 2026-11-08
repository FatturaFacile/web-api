<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\QuoteLifecycleService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder as ModuleSalesOrder;

/**
 * Saves the quotes and sales orders of the sales API (and the orders imported from the shops): number from the gapless
 * series of the document type (never from the client), lines and totals in decimal arithmetic, status changes through
 * the state machine of the document.
 *
 * Confirming an order goes through the credit check of the company and cancelling it gives its reserved stock back
 * (both in the invoicing module). The lines of an order cannot be replaced once part of it was delivered or invoiced,
 * nor those of a quote that is no longer open: the documents downstream point at those lines.
 */
class SaveSalesDocument
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Create a quote or sales order with auto-numbering, line items and totals. The status is stored as given (the shops
     * are the masters of the orders they send); the sales API creates orders through createOrder().
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     */
    public function create(string $modelClass, string $sequenceType, string $numberColumn, array $data): Model
    {
        return DB::transaction(function () use ($modelClass, $sequenceType, $numberColumn, $data): Model {
            [$lines, $totals] = $this->computeLines($data['items']);
            unset($data['items'], $data['tenant_id'], $data[$numberColumn]);

            $number = $this->numbering->next($sequenceType, (int) date('Y', (int) strtotime((string) $data['date'])));

            /** @var Model $document */
            $document = new $modelClass;
            $document->fill($data);
            $document->tenant_id = app(TenantContext::class)->getTenantId();
            $document->{$numberColumn} = $number['number'];
            $document->forceFill($totals);
            $document->save();

            $this->createLines($document, $lines);
            $this->events->record($document, 'created');

            return $document->load(['items', 'contact']);
        });
    }

    /**
     * Creates an order of the sales API: a draft, confirmed behind the credit check when the status asked for is
     * `confirmed` (it can then end on credit hold).
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrder(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data): SalesOrder {
            $requested = $data['status'] ?? SalesOrderStatus::Draft->value;
            $confirm = $requested === SalesOrderStatus::Confirmed->value;

            /** @var SalesOrder $order */
            $order = $this->create(SalesOrder::class, 'sales_order', 'order_number', array_merge($data, ['status' => $confirm ? SalesOrderStatus::Draft->value : $requested]));

            if ($confirm) {
                $this->confirmOrder($order);
            }

            return $order->refresh()->load(['items', 'contact']);
        });
    }

    /**
     * Update header fields and, when provided, replace line items and recompute totals.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $document, array $data): Model
    {
        return DB::transaction(function () use ($document, $data): Model {
            $document = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            unset($data['tenant_id'], $data['quote_number'], $data['order_number']);
            $status = $data['status'] ?? null;
            $this->assertStatusTransition($document, $status);
            unset($data['status']);
            $totals = [];

            if (isset($data['items'])) {
                $this->assertLinesReplaceable($document);
                [$lines, $totals] = $this->computeLines($data['items']);
                $document->items()->delete();
                $this->createLines($document, $lines);
                unset($data['items']);
            }

            $document->fill($data)->forceFill($totals);
            $document->save();
            $this->applyStatus($document, is_string($status) ? $status : null);
            $this->events->record($document, 'updated');

            return $document->refresh()->load(['items', 'contact']);
        });
    }

    /**
     * Stores the lines of the document with its tenant, so the tenant-scoped models of the invoicing module can update
     * them (delivered, reserved and invoiced quantities).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function createLines(Model $document, array $lines): void
    {
        foreach ($lines as $line) {
            $document->items()->make($line)->forceFill(['tenant_id' => $document->getAttribute('tenant_id')])->save();
        }
    }

    /**
     * Lines with their decimal totals, and the document totals.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: list<array<string, mixed>>, 1: array{total_net: string, total_tax: string, total_gross: string}}
     */
    private function computeLines(array $items): array
    {
        $computed = $this->totals->calculateDecimal($items);
        $lines = [];

        foreach ($computed['items'] as $index => $line) {
            $lines[] = ['product_id' => $items[$index]['product_id'] ?? null, 'description' => (string) $items[$index]['description']] + $line;
        }

        return [$lines, ['total_net' => $computed['total_net'], 'total_tax' => $computed['total_tax'], 'total_gross' => $computed['total_gross']]];
    }

    /**
     * The new status of the document: an order confirmed goes through the credit check, an order cancelled gives its
     * stock back, a quote accepted must still be valid; any other status is stored once the state machine allowed it.
     */
    private function applyStatus(Model $document, ?string $status): void
    {
        if ($status === null || $status === $document->getAttribute('status')) {
            return;
        }

        if ($document instanceof SalesOrder && $status === SalesOrderStatus::Confirmed->value) {
            $this->confirmOrder($document);

            return;
        }

        if ($document instanceof SalesOrder && $status === SalesOrderStatus::Cancelled->value) {
            app(SalesOrderServiceInterface::class)->cancelOrder(ModuleSalesOrder::query()->findOrFail($document->getKey()), __('sales.order_cancelled_from_api'), (string) (Auth::id() ?? 'system'));

            return;
        }

        if ($document instanceof Quote && $status === QuoteStatus::Accepted->value) {
            app(QuoteLifecycleService::class)->assertWithinValidity($document);
        }

        $document->forceFill(['status' => $status])->save();
    }

    private function confirmOrder(SalesOrder $order): void
    {
        app(SalesOrderServiceInterface::class)->submitOrder(ModuleSalesOrder::query()->findOrFail($order->getKey()), (string) (Auth::id() ?? 'system'));
    }

    /**
     * @throws ValidationException when downstream documents point at the lines, or the document is closed
     */
    private function assertLinesReplaceable(Model $document): void
    {
        if ($document instanceof SalesOrder) {
            $moved = $document->items()->get()->contains(static fn (Model $item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->getAttribute('quantity_delivered')))
                || Decimal::isPositive(DocumentTotalsCalculator::decimal($item->getAttribute('quantity_invoiced')))
                || Decimal::isPositive(DocumentTotalsCalculator::decimal($item->getAttribute('quantity_reserved'))));

            if ($moved || in_array($document->getAttribute('status'), [SalesOrderStatus::Shipped->value, SalesOrderStatus::Completed->value, SalesOrderStatus::Cancelled->value, SalesOrderStatus::Rejected->value], true)) {
                throw ValidationException::withMessages(['items' => __('sales.order_lines_locked')]);
            }
        }

        if ($document instanceof Quote && ! in_array($document->getAttribute('status'), QuoteLifecycleService::OpenStatuses, true)) {
            throw ValidationException::withMessages(['items' => __('sales.quote_lines_locked')]);
        }
    }

    /**
     * A status changed by hand follows the state machine of the document, the same one the invoicing module obeys.
     *
     * @throws ValidationException when the new status cannot be reached from the current one
     */
    private function assertStatusTransition(Model $document, mixed $status): void
    {
        $statuses = match (true) {
            $document instanceof Quote => QuoteStatus::class,
            $document instanceof SalesOrder => SalesOrderStatus::class,
            default => null,
        };

        if ($statuses === null || ! is_string($status) || $status === $document->getAttribute('status')) {
            return;
        }

        $target = $statuses::tryFrom($status) ?? throw ValidationException::withMessages(['status' => __('validation.in', ['attribute' => 'status'])]);
        $statuses::assertTransition($document->getAttribute('status'), $target);
    }
}
