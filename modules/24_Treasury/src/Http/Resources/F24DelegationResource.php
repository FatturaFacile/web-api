<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\F24Delegation;

/**
 * @mixin F24Delegation
 */
final class F24DelegationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'taxpayer_fiscal_code' => $this->taxpayer_fiscal_code,
            'taxpayer_name' => $this->taxpayer_name,
            'taxpayer_first_name' => $this->taxpayer_first_name,
            'taxpayer_birth_date' => $this->taxpayer_birth_date?->toDateString(),
            'taxpayer_birth_place' => $this->taxpayer_birth_place,
            'taxpayer_birth_province' => $this->taxpayer_birth_province,
            'taxpayer_gender' => $this->taxpayer_gender,
            'payment_date' => $this->payment_date->toDateString(),
            'status' => $this->status,
            'total_debit' => (string) $this->total_debit,
            'total_credit' => (string) $this->total_credit,
            'total_payable' => (string) $this->total_payable,
            'file_name' => $this->file_name,
            'file_hash' => $this->file_hash,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn ($line): array => [
                'id' => $line->id,
                'section' => $line->section,
                'tax_code' => $line->tax_code,
                'institution_code' => $line->institution_code,
                'reference_number' => $line->reference_number,
                'installment' => $line->installment,
                'reference_period' => $line->reference_period,
                'reference_year' => $line->reference_year,
                'debit_amount' => (string) $line->debit_amount,
                'credit_amount' => (string) $line->credit_amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
