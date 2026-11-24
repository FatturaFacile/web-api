<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\TreasuryBankMovement;
use OpenApi\Attributes as OA;

/**
 * A bank fee, or interest earned or charged, on a bank account.
 */
#[OA\Schema(
    schema: 'TreasuryStoreBankChargeRequest',
    required: ['movement_type', 'amount', 'movement_date', 'idempotency_key'],
    properties: [
        new OA\Property(property: 'movement_type', type: 'string', enum: TreasuryBankMovement::ChargeTypes),
        new OA\Property(property: 'amount', type: 'string', example: '12.50'),
        new OA\Property(property: 'movement_date', type: 'string', format: 'date'),
        new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'idempotency_key', description: 'Client key: a retry with the same key returns the movement already recorded', type: 'string', maxLength: 100),
    ]
)]
final class StoreBankChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'movement_type' => ['required', Rule::in(TreasuryBankMovement::ChargeTypes)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
