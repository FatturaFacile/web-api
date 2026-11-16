<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\BankChangeApproval;
use Modules\SupplierPortal\Models\BankChangeRequest;

/**
 * A bank detail change request with its callback verification and approvals.
 *
 * @mixin BankChangeRequest
 */
final class BankChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
            'source' => $this->source,
            'requested_by' => $this->requested_by,
            'account_holder' => $this->account_holder,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bank_name' => $this->bank_name,
            'previous_iban' => $this->previous_iban,
            'reason' => $this->reason,
            'status' => $this->status,
            'callback_verified_at' => $this->callback_verified_at?->toIso8601String(),
            'callback_verified_by' => $this->callback_verified_by,
            'callback_note' => $this->callback_note,
            'approvals_required' => max(2, (int) config('supplier_portal.bank_change_approvals')),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(static fn (BankChangeApproval $approval): array => [
                'user_id' => $approval->user_id,
                'decision' => $approval->decision,
                'comment' => $approval->comment,
                'decided_at' => $approval->decided_at->toIso8601String(),
            ])->values()->all()),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
