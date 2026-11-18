<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\ServiceAmounts;

/**
 * The priced breakdown of a service report, shared by the signed PDF and the invoice conversion so both show the same
 * numbers: labor, travel, callout fee and spare parts, each with its list amount and the amount actually charged
 * (0.00 when the contract or the warranty covers it).
 */
final readonly class ServiceReportBilling
{
    public function __construct(private CoveragePolicy $coverage) {}

    /**
     * @return array{lines: list<array{kind: string, description: string, quantity: string, unit_price: string, list_amount: string, charged_amount: string, covered: bool, product_id: string|null}>, billable_total: string, covered_total: string, currency: string}
     */
    public function breakdown(DigitalServiceReport $report): array
    {
        $workOrder = WorkOrder::query()->where('tenant_id', $report->tenant_id)->findOrFail($report->work_order_id);
        $contract = $workOrder->service_contract_id === null
            ? null
            : ServiceContract::query()->where('tenant_id', $report->tenant_id)->find($workOrder->service_contract_id);
        $technician = $workOrder->assigned_technician_id === null
            ? null
            : ServiceTechnician::query()->where('tenant_id', $report->tenant_id)->find($workOrder->assigned_technician_id);

        $rate = ServiceAmounts::money($contract->hourly_labor_rate ?? $technician->hourly_billable_rate ?? '0');
        $coversLabor = $this->coverage->coversLabor($workOrder, $contract);
        $coversTravel = $this->coverage->coversTravelAndCallout($workOrder, $contract);
        $coversParts = $this->coverage->coversParts($workOrder, $contract);

        $lines = [];

        if (Decimal::isPositive(ServiceAmounts::text($report->labor_hours))) {
            $lines[] = $this->line('labor', 'labor', (string) $report->labor_hours, $rate, $coversLabor, null);
        }

        if (Decimal::isPositive(ServiceAmounts::text($report->travel_hours))) {
            $lines[] = $this->line('travel', 'travel', (string) $report->travel_hours, $rate, $coversTravel, null);
        }

        $callout = ServiceAmounts::money($contract->callout_fee_amount ?? '0');

        if ($report->apply_callout_fee && Decimal::isPositive(ServiceAmounts::text($callout))) {
            $lines[] = $this->line('callout', 'callout', '1', $callout, $coversTravel, null);
        }

        foreach (PartsConsumption::query()->where('work_order_id', $workOrder->id)->orderBy('created_at')->get() as $part) {
            $meta = (array) $part->metadata;
            $listPrice = ServiceAmounts::money($meta['list_unit_price'] ?? $part->unit_price ?? '0');
            $covered = (bool) $part->is_covered_by_warranty_or_contract || $coversParts;
            $lines[] = $this->line('part', (string) ($meta['description'] ?? $part->lot_number), (string) $part->quantity, $listPrice, $covered, (string) $part->product_id);
        }

        $billable = '0.00';
        $coveredTotal = '0.00';

        foreach ($lines as $line) {
            $billable = ServiceAmounts::add($billable, $line['charged_amount']);
            $coveredTotal = ServiceAmounts::add($coveredTotal, $line['covered'] ? $line['list_amount'] : '0.00');
        }

        return ['lines' => $lines, 'billable_total' => $billable, 'covered_total' => $coveredTotal, 'currency' => (string) ($workOrder->currency ?: 'EUR')];
    }

    /**
     * @return array{kind: string, description: string, quantity: string, unit_price: string, list_amount: string, charged_amount: string, covered: bool, product_id: string|null}
     */
    private function line(string $kind, string $description, string $quantity, string $unitPrice, bool $covered, ?string $productId): array
    {
        $listAmount = ServiceAmounts::lineTotal($quantity, $unitPrice);

        return [
            'kind' => $kind,
            'description' => $description,
            'quantity' => ServiceAmounts::text($quantity),
            'unit_price' => $unitPrice,
            'list_amount' => $listAmount,
            'charged_amount' => $covered ? '0.00' : $listAmount,
            'covered' => $covered,
            'product_id' => $productId,
        ];
    }
}
