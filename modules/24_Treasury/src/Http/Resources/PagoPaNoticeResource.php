<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\PagoPaPaymentNotice;
use OpenApi\Attributes as OA;

/**
 * A pagoPA payment notice.
 *
 * @mixin PagoPaPaymentNotice
 */
#[OA\Schema(
    schema: 'TreasuryPagoPaNoticeResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reference_type', type: 'string', nullable: true),
        new OA\Property(property: 'reference_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'debtor_name', type: 'string'),
        new OA\Property(property: 'debtor_fiscal_code', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'causale', type: 'string'),
        new OA\Property(property: 'iuv', description: '13 digits', type: 'string'),
        new OA\Property(property: 'notice_code', description: '18 digits', type: 'string'),
        new OA\Property(property: 'creditor_fiscal_code', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'expired', 'cancelled']),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
final class PagoPaNoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'debtor_name' => $this->debtor_name,
            'debtor_fiscal_code' => $this->debtor_fiscal_code,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'due_date' => $this->due_date->toDateString(),
            'causale' => $this->causale,
            'iuv' => $this->iuv,
            'notice_code' => $this->notice_code,
            'creditor_fiscal_code' => $this->creditor_fiscal_code,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
