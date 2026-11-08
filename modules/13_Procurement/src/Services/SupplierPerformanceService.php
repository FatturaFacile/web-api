<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Modules\Procurement\DTOs\EvaluateSupplierDTO;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;
use Modules\Procurement\Support\Decimal;

/**
 * Supplier evaluation computed from the documents of a period:
 * - delivery punctuality: receipt lines received by the expected date of their order line (or order), in percent;
 * - quality: good quantity (accepted without inspection, or released by the inspection, less returns) over the received
 *   quantity, in percent;
 * - price: value at the supplier price list over the value at the ordered price, capped at 100 (only lines with a list
 *   price count).
 * The overall score is the average of the available metrics; rating A from 90, B from 75, C from 60, D below.
 */
final readonly class SupplierPerformanceService
{
    public function __construct(private SupplierEvaluationService $evaluations) {}

    /**
     * The metrics of the supplier in the period.
     *
     * @return array{on_time_delivery_rate: ?string, quality_acceptance_rate: ?string, price_competitiveness_score: ?string, overall_score: ?string, overall_rating: ?string, receipt_lines: int, order_lines: int}
     */
    public function metrics(Supplier $supplier, string $from, string $to): array
    {
        $receiptLines = PurchaseReceiptLine::query()
            ->with(['orderLine.purchaseOrder', 'receipt'])
            ->whereHas('receipt', static fn ($receipt) => $receipt->whereBetween('received_date', [$from, $to])
                ->whereHas('purchaseOrder', static fn ($order) => $order->where('supplier_id', $supplier->id)))
            ->get();

        $onTime = 0;
        $received = '0';
        $good = '0';

        foreach ($receiptLines as $line) {
            $expected = $line->orderLine->expected_delivery_date ?? $line->orderLine->purchaseOrder->expected_delivery_date ?? null;

            if ($expected === null || $line->receipt->received_date->toDateString() <= $expected->toDateString()) {
                $onTime++;
            }

            $kept = $line->inspection_status === 'not_required' ? (string) $line->quantity_accepted : (string) $line->quantity_released;
            $received = Decimal::add($received, (string) $line->quantity_received, 4);
            $good = Decimal::add($good, Decimal::max('0', Decimal::sub($kept, (string) $line->quantity_returned, 4), 4), 4);
        }

        $orderLines = PurchaseOrderLine::query()
            ->whereNotNull('list_unit_price')
            ->whereHas('purchaseOrder', static fn ($order) => $order->where('supplier_id', $supplier->id)->whereBetween('date', [$from, $to])->whereNotIn('status', ['draft', 'cancelled', 'rejected']))
            ->get();

        $atList = '0';
        $atOrder = '0';

        foreach ($orderLines as $line) {
            $atList = Decimal::add($atList, Decimal::mul((string) $line->quantity_ordered, (string) $line->list_unit_price, 4), 4);
            $atOrder = Decimal::add($atOrder, Decimal::mul((string) $line->quantity_ordered, (string) $line->unit_price, 4), 4);
        }

        $count = $receiptLines->count();
        $punctuality = $count > 0 ? Decimal::percentOf((string) $onTime, (string) $count, 2) : null;
        $quality = Decimal::isPositive($received) ? Decimal::percentOf($good, $received, 2) : null;
        $price = Decimal::isPositive($atOrder) ? Decimal::min('100.00', Decimal::percentOf($atList, $atOrder, 2), 2) : null;

        $available = array_values(array_filter([$punctuality, $quality, $price], static fn (?string $value): bool => $value !== null));
        $overall = $available === [] ? null : Decimal::div(array_reduce($available, static fn (string $sum, string $value): string => Decimal::add($sum, $value, 2), '0'), (string) count($available), 2);

        return [
            'on_time_delivery_rate' => $punctuality,
            'quality_acceptance_rate' => $quality,
            'price_competitiveness_score' => $price,
            'overall_score' => $overall,
            'overall_rating' => $overall === null ? null : self::rating($overall),
            'receipt_lines' => $count,
            'order_lines' => $orderLines->count(),
        ];
    }

    /**
     * Records an evaluation of the period with the computed metrics (a missing metric counts as 100).
     */
    public function evaluate(Supplier $supplier, string $from, string $to, string $evaluatorUserId, ?string $comments): SupplierEvaluation
    {
        $metrics = $this->metrics($supplier, $from, $to);

        return $this->evaluations->recordEvaluation(new EvaluateSupplierDTO(
            supplierId: (string) $supplier->id,
            periodStart: $from,
            periodEnd: $to,
            onTimeDeliveryRate: (float) ($metrics['on_time_delivery_rate'] ?? '100'),
            qualityAcceptanceRate: (float) ($metrics['quality_acceptance_rate'] ?? '100'),
            priceCompetitivenessScore: (float) ($metrics['price_competitiveness_score'] ?? '100'),
            overallRating: $metrics['overall_rating'] ?? 'A',
            isApprovedVendor: (bool) $supplier->is_approved_vendor,
            evaluatorUserId: $evaluatorUserId,
            comments: $comments,
        ));
    }

    private static function rating(string $score): string
    {
        return match (true) {
            Decimal::cmp($score, '90', 2) >= 0 => 'A',
            Decimal::cmp($score, '75', 2) >= 0 => 'B',
            Decimal::cmp($score, '60', 2) >= 0 => 'C',
            default => 'D',
        };
    }
}
