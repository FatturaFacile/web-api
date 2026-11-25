<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Procurement\DTOs\MatchLineResult;
use Modules\Procurement\DTOs\MatchResult;
use Modules\Procurement\DTOs\MatchTolerance;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\InvoiceMatchType;
use Modules\Procurement\Enums\MatchDiscrepancyReason;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Models\InvoiceMatchLine;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Support\Decimal;

/**
 * Pure evaluation of a supplier invoice against a purchase order and its goods receipts (nothing is stored here).
 *
 * 3-way: every invoice line is aligned to an order line and compared with the order price, the quantity physically
 * received (accepted less returned) and what earlier invoices already billed, so duplicate billing, billing beyond the
 * goods received and unauthorised price drift are caught. 2-way (service lines, subcontracting): the same without the
 * receipt, against the quantity still open on the order. An invoice without lines is compared as a whole.
 */
final class InvoiceMatchingEngine
{
    private const int MinimumSimilarity = 70;

    public function __construct(private readonly InvoiceMatchToleranceResolver $tolerances) {}

    /**
     * An invoice without a resolvable purchase order is always a discrepancy: there is nothing to match it with.
     */
    public function evaluateWithoutOrder(PassiveInvoice $invoice): MatchResult
    {
        $lines = [];
        $position = 1;

        foreach ($invoice->lines as $line) {
            $lines[] = $this->unmatchedLine($position++, $line);
        }

        $net = $this->invoiceNet($invoice);

        return new MatchResult(
            type: InvoiceMatchType::ThreeWay,
            status: InvoiceMatchStatus::BlockedDiscrepancy,
            lines: $lines,
            reasons: [MatchDiscrepancyReason::NoPurchaseOrder->value],
            orderedTotal: '0.00',
            receivedTotal: '0.00',
            invoicedTotal: $net,
            varianceAmount: $net,
            variancePercent: Decimal::isPositive($net) ? '100.00' : '0.00',
            grIrAmount: '0.00',
            priceVarianceAmount: $net,
            discrepancyCount: max(1, count($lines)),
            currency: (string) $invoice->currency,
        );
    }

    public function evaluate(PassiveInvoice $invoice, PurchaseOrder $order): MatchResult
    {
        $this->tolerances->flush();

        $tenantId = (string) $order->tenant_id;
        $orderLines = PurchaseOrderLine::query()->with('catalog')->where('purchase_order_id', $order->id)->orderBy('line_number')->get();
        $type = $this->typeOf($order, $orderLines);
        $received = $this->receivedQuantities($order);
        $invoiced = $this->previouslyInvoiced($order, (string) $invoice->id);
        $factor = $this->priceFactor($invoice, $order);
        $currency = (string) $invoice->currency;
        $supplierId = (string) $order->supplier_id;

        $result = $factor === null
            ? $this->currencyMismatch($invoice, $type, $currency)
            : ($invoice->lines->isEmpty()
                ? $this->evaluateHeader($invoice, $order, $orderLines, $type, $received, $invoiced, $factor, $tenantId, $supplierId)
                : $this->evaluateLines($invoice, $order, $orderLines, $type, $received, $invoiced, $factor, $tenantId, $supplierId));

        return $result;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     * @param  array<string, string>  $received
     * @param  array<string, string>  $previouslyInvoiced
     */
    private function evaluateLines(PassiveInvoice $invoice, PurchaseOrder $order, Collection $orderLines, InvoiceMatchType $type, array $received, array $previouslyInvoiced, string $factor, string $tenantId, string $supplierId): MatchResult
    {
        $currency = (string) $invoice->currency;
        $assignments = $this->align($invoice->lines, $orderLines);
        $running = [];
        $results = [];
        $position = 1;
        $usedOrderLines = [];
        $snapshot = [];

        foreach ($invoice->lines as $line) {
            $orderLine = $assignments[(string) $line->id] ?? null;

            if ($orderLine === null) {
                $results[] = $this->unmatchedLine($position++, $line);

                continue;
            }

            $key = (string) $orderLine->id;
            $usedOrderLines[$key] = $orderLine;
            $tolerance = $this->tolerances->resolve($tenantId, $supplierId, $orderLine->catalog?->item_category, $type, $currency);
            $snapshot[$tolerance->source.':'.($tolerance->ruleId ?? 'settings')] = $tolerance->toArray();

            $results[] = $this->evaluateLine(
                position: $position++,
                line: $line,
                orderLine: $orderLine,
                order: $order,
                received: $received[$key] ?? '0.0000',
                alreadyInvoiced: Decimal::add($previouslyInvoiced[$key] ?? '0.0000', $running[$key] ?? '0.0000', 4),
                factor: $factor,
                tolerance: $tolerance,
            );

            $running[$key] = Decimal::add($running[$key] ?? '0.0000', $this->quantityOf($line), 4);
        }

        $ordered = '0.00';
        $receivedValue = '0.00';

        foreach ($usedOrderLines as $key => $orderLine) {
            $price = Decimal::mul((string) $orderLine->unit_price, $factor, 6);
            $ordered = Decimal::add($ordered, Decimal::mul($this->orderedQuantity($orderLine), $price, 2), 2);
            $receivedValue = Decimal::add($receivedValue, Decimal::mul($received[$key] ?? '0.0000', $price, 2), 2);
        }

        return $this->summarize($type, $results, $ordered, $receivedValue, $this->invoiceNet($invoice), $currency, array_values($snapshot));
    }

    /**
     * An invoice that carries no lines (an OCR header, a summary eBill) is compared as one amount with the value still
     * billable on the order.
     *
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     * @param  array<string, string>  $received
     * @param  array<string, string>  $previouslyInvoiced
     */
    private function evaluateHeader(PassiveInvoice $invoice, PurchaseOrder $order, Collection $orderLines, InvoiceMatchType $type, array $received, array $previouslyInvoiced, string $factor, string $tenantId, string $supplierId): MatchResult
    {
        $currency = (string) $invoice->currency;
        $tolerance = $this->tolerances->resolve($tenantId, $supplierId, null, $type, $currency);
        $billable = '0.00';
        $ordered = '0.00';
        $receivedValue = '0.00';
        $receiptRequired = false;

        foreach ($orderLines as $orderLine) {
            $key = (string) $orderLine->id;
            $price = Decimal::mul((string) $orderLine->unit_price, $factor, 6);
            $needsReceipt = $this->needsReceipt($order, $orderLine);
            $receiptRequired = $receiptRequired || $needsReceipt;
            $base = $needsReceipt ? ($received[$key] ?? '0.0000') : $this->orderedQuantity($orderLine);
            $open = Decimal::max(Decimal::sub($base, $previouslyInvoiced[$key] ?? '0.0000', 4), '0.0000', 4);
            $billable = Decimal::add($billable, Decimal::mul($open, $price, 2), 2);
            $ordered = Decimal::add($ordered, Decimal::mul($this->orderedQuantity($orderLine), $price, 2), 2);
            $receivedValue = Decimal::add($receivedValue, Decimal::mul($received[$key] ?? '0.0000', $price, 2), 2);
        }

        $alreadyBilled = $this->headerInvoiced($order, (string) $invoice->id);
        $billable = Decimal::max(Decimal::sub($billable, $alreadyBilled, 2), '0.00', 2);

        $net = $this->invoiceNet($invoice);
        $variance = Decimal::sub($net, $billable, 2);
        $allowed = $tolerance->allowedAmount($billable);
        $blocked = Decimal::cmp($variance, $allowed, 2) > 0;
        $reasons = [];
        $duplicate = Decimal::isPositive($alreadyBilled) && ! Decimal::isPositive($billable);

        if ($blocked) {
            $reasons[] = match (true) {
                $duplicate => MatchDiscrepancyReason::DuplicateBilling->value,
                $receiptRequired && ! Decimal::isPositive($billable) => MatchDiscrepancyReason::NotReceived->value,
                default => MatchDiscrepancyReason::HeaderAboveTolerance->value,
            };
        }

        $status = match (true) {
            $blocked && $duplicate => InvoiceMatchLineStatus::QuantityVariance,
            $blocked && $reasons === [MatchDiscrepancyReason::NotReceived->value] => InvoiceMatchLineStatus::NotReceived,
            $blocked => InvoiceMatchLineStatus::PriceVariance,
            Decimal::cmp($variance, '0.00', 2) <= 0 => InvoiceMatchLineStatus::Matched,
            default => InvoiceMatchLineStatus::WithinTolerance,
        };

        $line = new MatchLineResult(
            lineNumber: 1,
            passiveInvoiceLineId: null,
            purchaseOrderLineId: null,
            description: null,
            status: $status,
            receiptRequired: $receiptRequired,
            orderedQuantity: '1.0000',
            receivedQuantity: '0.0000',
            previouslyInvoicedQuantity: '0.0000',
            invoicedQuantity: '1.0000',
            orderedUnitPrice: Decimal::of($billable, 6),
            invoicedUnitPrice: Decimal::of($net, 6),
            quantityVariance: '0.0000',
            priceVariance: Decimal::of($variance, 6),
            priceVariancePercent: $this->percent($variance, $billable),
            amountVariance: $variance,
            allowedQuantityVariance: '0.0000',
            allowedAmountVariance: $allowed,
            grIrAmount: $blocked || ! $receiptRequired ? '0.00' : Decimal::min($net, $billable, 2),
            toleranceRuleId: $tolerance->ruleId,
            reasons: $reasons,
        );

        return $this->summarize($type, [$line], $ordered, $receivedValue, $net, $currency, [$tolerance->toArray()]);
    }

    private function evaluateLine(int $position, PassiveInvoiceLine $line, PurchaseOrderLine $orderLine, PurchaseOrder $order, string $received, string $alreadyInvoiced, string $factor, MatchTolerance $tolerance): MatchLineResult
    {
        $needsReceipt = $this->needsReceipt($order, $orderLine);
        $orderedQty = $this->orderedQuantity($orderLine);
        $orderedPrice = Decimal::mul((string) $orderLine->unit_price, $factor, 6);
        $invoicedQty = $this->quantityOf($line);
        $invoicedPrice = $this->unitPriceOf($line, $invoicedQty);

        $baseQty = $needsReceipt ? $received : $orderedQty;
        $billable = Decimal::max(Decimal::sub($baseQty, $alreadyInvoiced, 4), '0.0000', 4);
        $quantityVariance = Decimal::sub($invoicedQty, $billable, 4);
        $allowedQuantity = $tolerance->allowedQuantity($billable);

        $priceVariance = Decimal::sub($invoicedPrice, $orderedPrice, 6);
        $amountVariance = Decimal::mul($priceVariance, $invoicedQty, 2);
        $allowedAmount = $tolerance->allowedAmount(Decimal::mul($orderedPrice, $invoicedQty, 2));

        $reasons = [];
        $status = null;

        if ($needsReceipt && ! Decimal::isPositive($received) && Decimal::isPositive($invoicedQty)) {
            $reasons[] = MatchDiscrepancyReason::NotReceived->value;
            $status = InvoiceMatchLineStatus::NotReceived;
        } elseif (Decimal::cmp($quantityVariance, $allowedQuantity, 4) > 0) {
            $reasons[] = $this->quantityReason($needsReceipt, $baseQty, $alreadyInvoiced);
            $status = InvoiceMatchLineStatus::QuantityVariance;
        }

        if (Decimal::cmp($amountVariance, $allowedAmount, 2) > 0) {
            $reasons[] = MatchDiscrepancyReason::PriceAboveTolerance->value;
            $status ??= InvoiceMatchLineStatus::PriceVariance;
        }

        $status ??= (Decimal::cmp($amountVariance, '0.00', 2) !== 0 || Decimal::cmp($quantityVariance, '0.0000', 4) > 0)
            ? InvoiceMatchLineStatus::WithinTolerance
            : InvoiceMatchLineStatus::Matched;

        $clearsAccrual = $needsReceipt && ! $status->isBlocking();

        return new MatchLineResult(
            lineNumber: $position,
            passiveInvoiceLineId: (string) $line->id,
            purchaseOrderLineId: (string) $orderLine->id,
            description: $line->description,
            status: $status,
            receiptRequired: $needsReceipt,
            orderedQuantity: $orderedQty,
            receivedQuantity: $received,
            previouslyInvoicedQuantity: Decimal::of($alreadyInvoiced, 4),
            invoicedQuantity: $invoicedQty,
            orderedUnitPrice: $orderedPrice,
            invoicedUnitPrice: $invoicedPrice,
            quantityVariance: $quantityVariance,
            priceVariance: $priceVariance,
            priceVariancePercent: $this->percent($priceVariance, $orderedPrice),
            amountVariance: $amountVariance,
            allowedQuantityVariance: $allowedQuantity,
            allowedAmountVariance: $allowedAmount,
            grIrAmount: $clearsAccrual ? Decimal::mul(Decimal::min($invoicedQty, $billable, 4), $orderedPrice, 2) : '0.00',
            toleranceRuleId: $tolerance->ruleId,
            reasons: $reasons,
        );
    }

    private function quantityReason(bool $needsReceipt, string $baseQty, string $alreadyInvoiced): string
    {
        $fullyBilled = Decimal::isPositive($alreadyInvoiced) && Decimal::cmp($alreadyInvoiced, $baseQty, 4) >= 0;

        return match (true) {
            $fullyBilled => MatchDiscrepancyReason::DuplicateBilling->value,
            $needsReceipt => MatchDiscrepancyReason::QuantityAboveReceived->value,
            default => MatchDiscrepancyReason::QuantityAboveOrdered->value,
        };
    }

    /**
     * @param  list<MatchLineResult>  $lines
     * @param  list<array<string, string|null>>  $snapshot
     */
    private function summarize(InvoiceMatchType $type, array $lines, string $ordered, string $receivedValue, string $invoiceNet, string $currency, array $snapshot): MatchResult
    {
        $blocking = array_filter($lines, static fn (MatchLineResult $line): bool => $line->status->isBlocking());
        $withinTolerance = array_filter($lines, static fn (MatchLineResult $line): bool => $line->status === InvoiceMatchLineStatus::WithinTolerance);

        $status = match (true) {
            $blocking !== [] => InvoiceMatchStatus::BlockedDiscrepancy,
            $withinTolerance !== [] => InvoiceMatchStatus::WithinTolerance,
            default => InvoiceMatchStatus::Matched,
        };

        $reasons = [];
        $variance = '0.00';
        $grIr = '0.00';

        foreach ($lines as $line) {
            foreach ($line->reasons as $reason) {
                $reasons[$reason] = $reason;
            }

            $overBilled = Decimal::cmp($line->quantityVariance, '0.0000', 4) > 0 ? Decimal::mul($line->quantityVariance, $line->orderedUnitPrice, 2) : '0.00';
            $unmatchedValue = $line->purchaseOrderLineId === null && $line->passiveInvoiceLineId !== null ? Decimal::mul($line->invoicedQuantity, $line->invoicedUnitPrice, 2) : '0.00';
            $variance = Decimal::add($variance, Decimal::add($line->amountVariance, Decimal::add($overBilled, $unmatchedValue, 2), 2), 2);
            $grIr = Decimal::add($grIr, $line->grIrAmount, 2);
        }

        return new MatchResult(
            type: $type,
            status: $status,
            lines: $lines,
            reasons: array_values($reasons),
            orderedTotal: $ordered,
            receivedTotal: $receivedValue,
            invoicedTotal: $invoiceNet,
            varianceAmount: $variance,
            variancePercent: Decimal::max('-999.99', Decimal::min($this->percent($variance, $invoiceNet), '999.99', 2), 2),
            grIrAmount: $grIr,
            priceVarianceAmount: Decimal::max(Decimal::sub($invoiceNet, $grIr, 2), '0.00', 2),
            discrepancyCount: count($blocking),
            currency: $currency,
            toleranceSnapshot: $snapshot,
        );
    }

    private function unmatchedLine(int $position, PassiveInvoiceLine $line): MatchLineResult
    {
        $quantity = $this->quantityOf($line);

        return new MatchLineResult(
            lineNumber: $position,
            passiveInvoiceLineId: (string) $line->id,
            purchaseOrderLineId: null,
            description: $line->description,
            status: InvoiceMatchLineStatus::Unmatched,
            receiptRequired: true,
            orderedQuantity: '0.0000',
            receivedQuantity: '0.0000',
            previouslyInvoicedQuantity: '0.0000',
            invoicedQuantity: $quantity,
            orderedUnitPrice: '0.000000',
            invoicedUnitPrice: $this->unitPriceOf($line, $quantity),
            quantityVariance: $quantity,
            priceVariance: '0.000000',
            priceVariancePercent: '0.00',
            amountVariance: '0.00',
            allowedQuantityVariance: '0.0000',
            allowedAmountVariance: '0.00',
            grIrAmount: '0.00',
            toleranceRuleId: null,
            reasons: [MatchDiscrepancyReason::UnmatchedLine->value],
        );
    }

    private function currencyMismatch(PassiveInvoice $invoice, InvoiceMatchType $type, string $currency): MatchResult
    {
        $result = $this->evaluateWithoutOrder($invoice);

        return new MatchResult(
            type: $type,
            status: InvoiceMatchStatus::BlockedDiscrepancy,
            lines: $result->lines,
            reasons: [MatchDiscrepancyReason::CurrencyMismatch->value],
            orderedTotal: '0.00',
            receivedTotal: '0.00',
            invoicedTotal: $result->invoicedTotal,
            varianceAmount: $result->invoicedTotal,
            variancePercent: $result->variancePercent,
            grIrAmount: '0.00',
            priceVarianceAmount: $result->invoicedTotal,
            discrepancyCount: $result->discrepancyCount,
            currency: $currency,
        );
    }

    /**
     * Aligns every invoice line to an order line: by the part number or SKU quoted on the line, by the same
     * description, by the closest description (at least 70 % alike, the nearest unit price on a tie), and finally by an
     * identical unit price when only one order line has it.
     *
     * @param  Collection<int, PassiveInvoiceLine>  $invoiceLines
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     * @return array<string, PurchaseOrderLine>
     */
    private function align(Collection $invoiceLines, Collection $orderLines): array
    {
        $assigned = [];

        foreach ($invoiceLines as $line) {
            $text = $this->normalizeText((string) $line->description);
            $match = null;

            foreach ($orderLines as $orderLine) {
                $codes = array_filter([$orderLine->catalog?->supplier_part_number, $orderLine->catalog?->internal_sku]);

                foreach ($codes as $code) {
                    $needle = PurchaseOrderReferenceExtractor::normalize((string) $code);
                    if ($needle !== '' && str_contains(PurchaseOrderReferenceExtractor::normalize((string) $line->description), $needle)) {
                        $match = $orderLine;
                        break 2;
                    }
                }

                if ($text !== '' && $text === $this->normalizeText((string) $orderLine->description)) {
                    $match = $orderLine;
                    break;
                }
            }

            if ($match === null) {
                $match = $this->closestByDescription($line, $text, $orderLines) ?? $this->uniqueByPrice($line, $orderLines);
            }

            if ($match !== null) {
                $assigned[(string) $line->id] = $match;
            }
        }

        return $assigned;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function closestByDescription(PassiveInvoiceLine $line, string $text, Collection $orderLines): ?PurchaseOrderLine
    {
        if ($text === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $bestGap = null;
        $price = $this->unitPriceOf($line, $this->quantityOf($line));

        foreach ($orderLines as $orderLine) {
            similar_text($text, $this->normalizeText((string) $orderLine->description), $score);
            $gap = Decimal::abs(Decimal::sub($price, (string) $orderLine->unit_price, 6), 6);

            if ($score >= self::MinimumSimilarity && ($score > $bestScore || ($score === $bestScore && $bestGap !== null && Decimal::cmp($gap, $bestGap, 6) < 0))) {
                $best = $orderLine;
                $bestScore = $score;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function uniqueByPrice(PassiveInvoiceLine $line, Collection $orderLines): ?PurchaseOrderLine
    {
        $price = $this->unitPriceOf($line, $this->quantityOf($line));
        $candidates = $orderLines->filter(static fn (PurchaseOrderLine $orderLine): bool => Decimal::cmp(Decimal::of((string) $orderLine->unit_price, 6), $price, 6) === 0);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function typeOf(PurchaseOrder $order, Collection $orderLines): InvoiceMatchType
    {
        $needsReceipt = $orderLines->contains(fn (PurchaseOrderLine $line): bool => $this->needsReceipt($order, $line));

        return $needsReceipt || $orderLines->isEmpty() ? InvoiceMatchType::ThreeWay : InvoiceMatchType::TwoWay;
    }

    private function needsReceipt(PurchaseOrder $order, PurchaseOrderLine $line): bool
    {
        return $order->order_type !== 'subcontract' && (bool) ($line->receipt_required ?? true);
    }

    private function orderedQuantity(PurchaseOrderLine $line): string
    {
        return Decimal::max(Decimal::sub((string) $line->quantity_ordered, (string) ($line->quantity_cancelled ?? '0'), 4), '0.0000', 4);
    }

    /**
     * Goods received per order line: accepted less returned to the supplier.
     *
     * @return array<string, string>
     */
    private function receivedQuantities(PurchaseOrder $order): array
    {
        $received = [];

        $lines = PurchaseReceiptLine::query()
            ->whereHas('receipt', static fn ($receipt) => $receipt->where('purchase_order_id', $order->id))
            ->get();

        foreach ($lines as $line) {
            $key = (string) $line->purchase_order_line_id;
            $net = Decimal::sub((string) $line->quantity_accepted, (string) $line->quantity_returned, 4);
            $received[$key] = Decimal::max(Decimal::add($received[$key] ?? '0.0000', $net, 4), '0.0000', 4);
        }

        return $received;
    }

    /**
     * Quantity per order line already billed by the other invoices of the order that were matched or approved.
     *
     * @return array<string, string>
     */
    private function previouslyInvoiced(PurchaseOrder $order, string $invoiceId): array
    {
        $runIds = InvoiceMatchingRun::query()
            ->where('purchase_order_id', $order->id)
            ->where('passive_invoice_id', '!=', $invoiceId)
            ->where('is_current', true)
            ->whereIn('status', InvoiceMatchStatus::clearingValues())
            ->pluck('id');

        $billed = [];

        foreach (InvoiceMatchLine::query()->whereIn('run_id', $runIds)->whereNotNull('purchase_order_line_id')->get() as $line) {
            $key = (string) $line->purchase_order_line_id;
            $billed[$key] = Decimal::add($billed[$key] ?? '0.0000', (string) $line->invoiced_quantity, 4);
        }

        return $billed;
    }

    /**
     * Value of the other invoices of the order that were matched as a whole (no lines) and cleared.
     */
    private function headerInvoiced(PurchaseOrder $order, string $invoiceId): string
    {
        $runIds = InvoiceMatchingRun::query()
            ->where('purchase_order_id', $order->id)
            ->where('passive_invoice_id', '!=', $invoiceId)
            ->where('is_current', true)
            ->whereIn('status', InvoiceMatchStatus::clearingValues())
            ->pluck('id');

        $billed = '0.00';

        foreach (InvoiceMatchLine::query()->whereIn('run_id', $runIds)->whereNull('purchase_order_line_id')->whereNull('passive_invoice_line_id')->get() as $line) {
            $billed = Decimal::add($billed, Decimal::mul((string) $line->invoiced_quantity, (string) $line->invoiced_unit_price, 2), 2);
        }

        return $billed;
    }

    /**
     * Factor that turns an order price into the currency of the invoice; null when the two currencies cannot be
     * compared because a rate is missing.
     */
    private function priceFactor(PassiveInvoice $invoice, PurchaseOrder $order): ?string
    {
        $invoiceCurrency = strtoupper((string) $invoice->currency);
        $orderCurrency = strtoupper((string) $order->currency);

        if ($invoiceCurrency === $orderCurrency) {
            return '1.000000';
        }

        $accounting = TenantAccountingCurrency::of((string) $order->tenant_id);
        $orderRate = $orderCurrency === $accounting ? '1' : (string) ($order->exchange_rate ?? '');
        $invoiceRate = $invoiceCurrency === $accounting ? '1' : (string) ($invoice->exchange_rate ?? '');

        if (! Decimal::isPositive($orderRate) || ! Decimal::isPositive($invoiceRate)) {
            return null;
        }

        return Decimal::div($orderRate, $invoiceRate, 6);
    }

    private function invoiceNet(PassiveInvoice $invoice): string
    {
        $net = (string) ($invoice->total_net ?? '0');

        if (Decimal::isPositive($net)) {
            return Decimal::of($net, 2);
        }

        $linesTotal = $invoice->lines->reduce(static fn (string $sum, PassiveInvoiceLine $line): string => Decimal::add($sum, (string) $line->line_total, 2), '0.00');

        return Decimal::isPositive($linesTotal)
            ? $linesTotal
            : Decimal::max(Decimal::sub((string) ($invoice->total_amount ?? '0'), (string) ($invoice->total_tax ?? '0'), 2), '0.00', 2);
    }

    private function quantityOf(PassiveInvoiceLine $line): string
    {
        $quantity = (string) ($line->quantity ?? '');

        return Decimal::isPositive($quantity) ? Decimal::of($quantity, 4) : '1.0000';
    }

    private function unitPriceOf(PassiveInvoiceLine $line, string $quantity): string
    {
        $price = (string) ($line->unit_price ?? '0');
        $total = (string) ($line->line_total ?? '0');

        if (Decimal::isPositive($price)) {
            // A line discount (ScontoMaggiorazione) leaves the list price in `unit_price` and the discounted amount in
            // `line_total`: the price actually invoiced is the total over the quantity. A gap within a cent is rounding.
            $gap = Decimal::abs(Decimal::sub(Decimal::mul($price, $quantity, 2), $total, 2), 2);

            if (Decimal::isPositive($total) && Decimal::cmp($gap, '0.01', 2) > 0) {
                return Decimal::div($total, $quantity, 6);
            }

            return Decimal::of($price, 6);
        }

        return Decimal::div((string) ($line->line_total ?? '0'), $quantity, 6);
    }

    private function percent(string $part, string $whole): string
    {
        return Decimal::max('-999.99', Decimal::min(Decimal::percentOf($part, $whole, 2), '999.99', 2), 2);
    }
}
