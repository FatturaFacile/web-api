<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Logistics\Models\ConsignmentConsumptionDeclaration;
use Modules\Logistics\Services\ConsignmentService;

final class DeclareConsignmentConsumptionAction
{
    public function __construct(private readonly ConsignmentService $consignment) {}

    /**
     * Posts a periodic consumption declaration: reconciles the consumed quantities, shifts ownership and drafts the
     * Domain 01 sales invoice (customer consignment) or the purchase document (vendor consignment).
     *
     * @param  array<string, mixed>  $data  validated payload including `lines`
     */
    public function execute(array $data): ConsignmentConsumptionDeclaration
    {
        /** @var list<array{product_id: string, quantity: float|int|string, unit_price?: float|int|string|null, tax_rate?: float|int|string|null}> $lines */
        $lines = $data['lines'];
        /** @var array{warehouse_id: string, agreement_id?: ?string, period_start: string, period_end: string, notes?: ?string} $header */
        $header = $data;

        return $this->consignment->declareConsumption($header, $lines);
    }
}
