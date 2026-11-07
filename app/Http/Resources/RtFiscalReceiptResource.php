<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RtFiscalReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Fiscal receipt of a POS sale. `simulated` and `reached_tax_authority` say plainly whether the Agenzia delle Entrate
 * received anything: with the offline printer it did not.
 *
 * @mixin RtFiscalReceipt
 */
#[OA\Schema(
    schema: 'RtFiscalReceiptResource',
    description: 'Fiscal receipt of a POS sale. With the simulated printer `rt_status` is `simulated`, `transmitted_at` is null and `reached_tax_authority` is false.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'pos_sale_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'z_report_number', type: 'string'),
        new OA\Property(property: 'fiscal_document_number', type: 'string'),
        new OA\Property(property: 'xml_lot_id', type: 'string'),
        new OA\Property(property: 'rt_status', type: 'string', enum: ['transmitted', 'simulated', 'error', 'pending']),
        new OA\Property(property: 'transmitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'simulated', type: 'boolean'),
        new OA\Property(property: 'reached_tax_authority', type: 'boolean'),
        new OA\Property(property: 'notice', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class RtFiscalReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(),
            'simulated' => $this->isSimulated(),
            'reached_tax_authority' => $this->reachedTaxAuthority(),
            'notice' => $this->isSimulated() ? __('pos.rt_simulated_notice') : null,
        ];
    }
}
