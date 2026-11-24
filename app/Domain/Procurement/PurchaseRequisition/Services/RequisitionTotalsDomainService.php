<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineConversionStatus;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use Modules\Procurement\Support\Decimal;

/**
 * Pure domain calculations for Purchase Requisition totals, cost-center grouping, and conversion status transitions.
 */
final class RequisitionTotalsDomainService
{
    public function computeLineTotal(string|int|float $quantity, string|int|float $unitPrice): string
    {
        return Decimal::mul(Decimal::of($quantity, 4), Decimal::of($unitPrice, 4), 2);
    }

    /**
     * @param  iterable<int, array{quantity: string|int|float, estimated_unit_price: string|int|float, cost_center_id?: ?string}>  $lines
     * @return array{total_estimated_amount: string, amounts_by_cost_center: array<string, string>}
     */
    public function summarizeDraftPayload(?string $headerCostCenterId, iterable $lines): array
    {
        $total = '0.00';
        $byCostCenter = [];

        foreach ($lines as $line) {
            $lineTotal = $this->computeLineTotal($line['quantity'], $line['estimated_unit_price']);
            $total = Decimal::add($total, $lineTotal, 2);

            $effectiveCostCenter = isset($line['cost_center_id']) && trim((string) $line['cost_center_id']) !== ''
                ? (string) $line['cost_center_id']
                : ($headerCostCenterId !== null && trim($headerCostCenterId) !== '' ? $headerCostCenterId : '__UNASSIGNED__');

            $byCostCenter[$effectiveCostCenter] = Decimal::add($byCostCenter[$effectiveCostCenter] ?? '0.00', $lineTotal, 2);
        }

        return [
            'total_estimated_amount' => $total,
            'amounts_by_cost_center' => $byCostCenter,
        ];
    }

    /**
     * Groups persisted requisition lines by effective cost center (line override or header fallback).
     *
     * @return array<string, string>
     */
    public function amountsByCostCenter(PurchaseRequisition $requisition): array
    {
        $headerCostCenter = $requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null;
        $byCostCenter = [];

        foreach ($requisition->lines as $line) {
            /** @var PurchaseRequisitionLine $line */
            $effectiveCostCenter = $line->cost_center_id !== null && (string) $line->cost_center_id !== ''
                ? (string) $line->cost_center_id
                : ($headerCostCenter !== null && $headerCostCenter !== '' ? $headerCostCenter : '__UNASSIGNED__');

            $lineTotal = $line->effectiveLineTotal();
            $byCostCenter[$effectiveCostCenter] = Decimal::add($byCostCenter[$effectiveCostCenter] ?? '0.00', $lineTotal, 2);
        }

        return $byCostCenter;
    }

    public function resolveLineConversionStatus(string $requestedQuantity, string $convertedQuantity): RequisitionLineConversionStatus
    {
        $requested = Decimal::of($requestedQuantity, 4);
        $converted = Decimal::of($convertedQuantity, 4);

        if (Decimal::cmp($converted, '0', 4) <= 0) {
            return RequisitionLineConversionStatus::Unconverted;
        }

        if (Decimal::cmp($converted, $requested, 4) >= 0) {
            return RequisitionLineConversionStatus::FullyConverted;
        }

        return RequisitionLineConversionStatus::PartiallyConverted;
    }

    /**
     * @param  iterable<int, PurchaseRequisitionLine>  $lines
     */
    public function resolveHeaderConversionStatus(iterable $lines): RequisitionStatus
    {
        $hasAnyConverted = false;
        $allFullyConverted = true;
        $count = 0;

        foreach ($lines as $line) {
            $count++;
            $converted = Decimal::of((string) ($line->converted_quantity ?? '0'), 4);
            $requested = $line->effectiveQuantity();

            if (Decimal::cmp($converted, '0', 4) > 0) {
                $hasAnyConverted = true;
            }

            if (Decimal::cmp($converted, $requested, 4) < 0) {
                $allFullyConverted = false;
            }
        }

        if ($count === 0 || ! $hasAnyConverted) {
            return RequisitionStatus::Approved;
        }

        return $allFullyConverted ? RequisitionStatus::Converted : RequisitionStatus::PartiallyConverted;
    }
}
