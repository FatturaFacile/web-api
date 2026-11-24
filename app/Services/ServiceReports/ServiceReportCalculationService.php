<?php

declare(strict_types=1);

namespace App\Services\ServiceReports;

use App\Models\ServiceReport;
use App\Models\ServiceReportLine;

class ServiceReportCalculationService
{
    /**
     * Compute line amounts based on quantity, unit price, discount, and tax rate.
     *
     * @param  array<string, mixed>  $lineData
     * @return array<string, mixed>
     */
    public function computeLineAmounts(array $lineData): array
    {
        $quantity = (float) ($lineData['quantity'] ?? 1);
        $unitPrice = (float) ($lineData['unit_price'] ?? 0);
        $discountPercent = (float) ($lineData['discount_percent'] ?? 0);
        $taxRate = (float) ($lineData['tax_rate'] ?? 0);

        $lineNet = round($quantity * $unitPrice * (1 - ($discountPercent / 100)), 4);
        $lineTax = round($lineNet * ($taxRate / 100), 4);
        $lineTotal = round($lineNet + $lineTax, 4);

        return array_merge($lineData, [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'tax_rate' => $taxRate,
            'line_net' => $lineNet,
            'line_tax' => $lineTax,
            'line_total' => $lineTotal,
        ]);
    }

    /**
     * Recalculate and update the totals on the service report.
     */
    public function recalculateReportTotals(ServiceReport $report): ServiceReport
    {
        $report->loadMissing(['lines.lineType']);

        $totalNet = 0.0;
        $totalTax = 0.0;
        $totalHours = 0.0;

        /** @var ServiceReportLine $line */
        foreach ($report->lines as $line) {
            $totalNet += (float) $line->line_net;
            $totalTax += (float) $line->line_tax;

            if ($line->lineType && $line->lineType->code === 'labor') {
                $totalHours += (float) $line->quantity;
            }
        }

        $totalGross = round($totalNet + $totalTax, 4);

        $report->update([
            'total_net' => round($totalNet, 4),
            'total_tax' => round($totalTax, 4),
            'total_gross' => $totalGross,
            'total_hours' => round($totalHours, 2),
        ]);

        return $report;
    }
}
