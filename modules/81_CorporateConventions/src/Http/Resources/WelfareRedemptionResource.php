<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Models\WelfareVoucherRedemption;

/**
 * @mixin WelfareVoucherRedemption
 */
final class WelfareRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_document_type' => $this->source_document_type->value,
            'source_document_id' => $this->source_document_id,
            'enrollment_id' => $this->enrollment_id,
            'jurisdiction' => $this->jurisdiction->value,
            'provider' => $this->provider->value,
            'welfare_category' => $this->welfare_category->value,
            'voucher_code_masked' => $this->voucher_code_masked,
            'currency' => $this->currency,
            'gross_voucher_amount' => $this->gross_voucher_amount,
            'merchant_fee_percentage' => $this->merchant_fee_percentage,
            'merchant_fee_amount' => $this->merchant_fee_amount,
            'net_expected_reimbursement' => $this->net_expected_reimbursement,
            'reimbursement_status' => $this->reimbursement_status->value,
            'settlement_batch_reference' => $this->settlement_batch_reference,
            'provider_self_invoice_number' => $this->provider_self_invoice_number,
            'settled_amount' => $this->settled_amount,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
