<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\PaymentProposal;
use Modules\Treasury\Models\PaymentProposalLine;
use OpenApi\Attributes as OA;

/**
 * A supplier payment proposal with its lines and, once approved, the payment batch it became.
 *
 * @mixin PaymentProposal
 */
#[OA\Schema(
    schema: 'TreasuryPaymentProposalResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'proposal_number', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'converted', 'rejected']),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'format', type: 'string'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
        new OA\Property(property: 'due_until', type: 'string', format: 'date'),
        new OA\Property(property: 'include_discounts', type: 'boolean'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_amount', type: 'string'),
        new OA\Property(property: 'total_discount', type: 'string'),
        new OA\Property(property: 'item_count', type: 'integer'),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid'),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
        new OA\Property(property: 'payment_batch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'selection_reason', type: 'string', enum: ['due', 'discount']),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'discount_deadline', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'open_amount', type: 'string'),
            new OA\Property(property: 'discount_amount', type: 'string'),
            new OA\Property(property: 'amount', type: 'string'),
        ], type: 'object')),
    ]
)]
final class PaymentProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proposal_number' => $this->proposal_number,
            'status' => $this->status,
            'bank_account_id' => $this->bank_account_id,
            'format' => $this->format,
            'execution_date' => $this->execution_date->toDateString(),
            'due_until' => $this->due_until->toDateString(),
            'include_discounts' => (bool) $this->include_discounts,
            'currency' => $this->currency,
            'total_amount' => (string) $this->total_amount,
            'total_discount' => (string) $this->total_discount,
            'item_count' => (int) $this->item_count,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'payment_batch_id' => $this->payment_batch_id,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (PaymentProposalLine $line): array => [
                'passive_invoice_id' => $line->passive_invoice_id,
                'supplier_id' => $line->supplier_id,
                'selection_reason' => $line->selection_reason,
                'due_date' => $line->due_date?->toDateString(),
                'discount_deadline' => $line->discount_deadline?->toDateString(),
                'open_amount' => (string) $line->open_amount,
                'discount_amount' => (string) $line->discount_amount,
                'amount' => (string) $line->amount,
            ])->values()->all()),
        ];
    }
}
