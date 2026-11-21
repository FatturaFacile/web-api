<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Support\Money;
use Modules\MrpPlanning\Domain\Contracts\ReleaseGatewayInterface;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Services\ProcurementNumberingService;

/**
 * Releases proposals into the existing modules: a draft purchase requisition of the procurement module and a planned
 * production order of the manufacturing module, through their own services.
 */
final class ReleaseGateway implements ReleaseGatewayInterface
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProductionPlanningServiceInterface $planning,
    ) {}

    public function createPurchaseRequisition(string $tenantId, string $requestorUserId, string $title, CarbonImmutable $requiredBy, array $lines, string $notes): string
    {
        $total = '0';
        foreach ($lines as $line) {
            $total = Money::add($total, Money::mul($line['quantity'], $line['unit_price']));
        }

        $requisition = PurchaseRequisition::create([
            'tenant_id' => $tenantId,
            'requisition_number' => $this->numbering->next(ProcurementNumberingService::Requisition, $tenantId),
            'title' => $title,
            'requestor_user_id' => $requestorUserId,
            'urgency' => 'medium',
            'required_by_date' => $requiredBy->toDateString(),
            'status' => 'draft',
            'total_estimated_amount' => Money::round($total),
            'currency' => Tenant::query()->whereKey($tenantId)->value('default_currency') ?? 'EUR',
            'notes' => $notes,
            'created_by' => Str::isUuid($requestorUserId) ? $requestorUserId : null,
        ]);

        foreach (array_values($lines) as $index => $line) {
            PurchaseRequisitionLine::create([
                'tenant_id' => $tenantId,
                'purchase_requisition_id' => $requisition->id,
                'line_number' => $index + 1,
                'description' => $line['description'],
                'quantity_requested' => $line['quantity'],
                'uom' => $line['uom'],
                'estimated_unit_price' => $line['unit_price'],
                'estimated_total_price' => Money::round(Money::mul($line['quantity'], $line['unit_price'])),
                'suggested_supplier_id' => $line['supplier_id'],
            ]);
        }

        return (string) $requisition->id;
    }

    public function createProductionOrder(string $tenantId, string $itemId, string $quantity, CarbonImmutable $plannedStart, CarbonImmutable $plannedEnd, ?string $bomId, ?string $createdBy): string
    {
        $order = $this->planning->planOrder(new CreateProductionOrderDTO(
            tenantId: $tenantId,
            mfgItemId: $itemId,
            targetQuantity: $quantity,
            plannedStartDate: $plannedStart->toDateString(),
            plannedEndDate: $plannedEnd->toDateString(),
            bomId: $bomId,
            notes: 'Released from MRP',
            createdBy: $createdBy,
        ));

        return (string) $order->id;
    }
}
