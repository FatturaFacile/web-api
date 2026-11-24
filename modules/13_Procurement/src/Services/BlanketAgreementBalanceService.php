<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Carbon;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Support\Decimal;

/**
 * Live balance of a blanket purchase agreement: the ceiling (plafond) and the guaranteed minimum spend against what the
 * call-off orders committed, what was received and what was invoiced, with the alerts that tell the buyer when the
 * ceiling is near or the minimum is at risk. Cancelled orders and cancelled quantities never count.
 */
final class BlanketAgreementBalanceService
{
    /** Days before the end of the agreement from which an unmet minimum spend raises an alert. */
    private const int MinimumRiskWindowDays = 90;

    /**
     * @return array{contract_id: string, currency: string, status: string, ceiling_amount: string, minimum_spend_amount: string|null, ordered_amount: string, received_amount: string, invoiced_amount: string, remaining_ceiling: string|null, ceiling_used_percent: string|null, minimum_shortfall: string|null, minimum_reached_percent: string|null, alert_threshold_percent: string, lines: list<array<string, string|null>>, alerts: list<array{code: string, severity: string}>}
     */
    public function balance(SupplierContract $contract, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $ordered = '0';
        $received = '0';
        $invoiced = '0';
        $perLine = [];

        $lines = PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', static fn ($query) => $query->where('supplier_contract_id', $contract->id)->where('status', '!=', 'cancelled'))
            ->get();

        foreach ($lines as $line) {
            $price = (string) $line->unit_price;
            $open = Decimal::max('0', Decimal::sub((string) $line->quantity_ordered, (string) $line->quantity_cancelled));
            $ordered = Decimal::add($ordered, Decimal::mul($open, $price));
            $received = Decimal::add($received, Decimal::mul(Decimal::max('0', Decimal::sub((string) $line->quantity_received, (string) $line->quantity_returned)), $price));
            $invoiced = Decimal::add($invoiced, Decimal::mul((string) $line->quantity_invoiced, $price));

            if ($line->supplier_contract_line_id !== null) {
                $perLine[$line->supplier_contract_line_id] = Decimal::add($perLine[$line->supplier_contract_line_id] ?? '0', Decimal::mul($open, $price));
            }
        }

        $ceiling = Decimal::of((string) $contract->total_committed_amount, 2);
        $minimum = $contract->minimum_spend_amount === null ? null : Decimal::of((string) $contract->minimum_spend_amount, 2);
        $threshold = Decimal::of((string) ($contract->alert_threshold_percent ?? '80'), 2);
        $hasCeiling = Decimal::isPositive($ceiling);
        $ordered = Decimal::round($ordered);

        $remainingCeiling = $hasCeiling ? Decimal::round(Decimal::sub($ceiling, $ordered)) : null;
        $usedPercent = $hasCeiling ? Decimal::percentOf($ordered, $ceiling) : null;
        $shortfall = $minimum === null ? null : Decimal::round(Decimal::max('0', Decimal::sub($minimum, $ordered)));
        $reachedPercent = $minimum !== null && Decimal::isPositive($minimum) ? Decimal::percentOf($ordered, $minimum) : null;

        $alerts = [];

        if ($hasCeiling && Decimal::cmp($ordered, $ceiling) > 0) {
            $alerts[] = ['code' => 'ceiling_exceeded', 'severity' => 'critical'];
        } elseif ($usedPercent !== null && Decimal::cmp($usedPercent, $threshold) >= 0) {
            $alerts[] = ['code' => 'ceiling_near', 'severity' => 'warning'];
        }

        if ($shortfall !== null && Decimal::isPositive($shortfall)) {
            $daysLeft = (int) $asOf->copy()->startOfDay()->diffInDays($contract->ends_at->copy()->startOfDay(), false);

            if ($daysLeft < 0) {
                $alerts[] = ['code' => 'minimum_not_met', 'severity' => 'critical'];
            } elseif ($daysLeft <= self::MinimumRiskWindowDays) {
                $alerts[] = ['code' => 'minimum_at_risk', 'severity' => 'warning'];
            }
        }

        return [
            'contract_id' => (string) $contract->id,
            'currency' => (string) ($contract->currency ?? 'EUR'),
            'status' => (string) $contract->status,
            'ceiling_amount' => $ceiling,
            'minimum_spend_amount' => $minimum,
            'ordered_amount' => $ordered,
            'received_amount' => Decimal::round($received),
            'invoiced_amount' => Decimal::round($invoiced),
            'remaining_ceiling' => $remainingCeiling,
            'ceiling_used_percent' => $usedPercent,
            'minimum_shortfall' => $shortfall,
            'minimum_reached_percent' => $reachedPercent,
            'alert_threshold_percent' => $threshold,
            'lines' => $this->lines($contract, $perLine),
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array<string, string>  $orderedByLine
     * @return list<array<string, string|null>>
     */
    private function lines(SupplierContract $contract, array $orderedByLine): array
    {
        $result = [];

        foreach ($contract->lines()->orderBy('price_valid_from')->get() as $line) {
            $committed = $line->committed_quantity === null ? null : (string) $line->committed_quantity;
            $released = (string) $line->released_quantity;

            $result[] = [
                'line_id' => (string) $line->id,
                'description' => (string) $line->description,
                'unit_price' => (string) $line->unit_price,
                'committed_quantity' => $committed,
                'released_quantity' => $released,
                'remaining_quantity' => $committed === null ? null : Decimal::max('0', Decimal::sub($committed, $released)),
                'ordered_amount' => Decimal::round($orderedByLine[(string) $line->id] ?? '0'),
            ];
        }

        return $result;
    }
}
