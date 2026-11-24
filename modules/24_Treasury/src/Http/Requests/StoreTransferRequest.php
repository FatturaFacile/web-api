<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * An internal transfer between two bank accounts of the tenant, in the same currency.
 */
#[OA\Schema(
    schema: 'TreasuryStoreTransferRequest',
    required: ['from_bank_account_id', 'to_bank_account_id', 'amount', 'transfer_date', 'idempotency_key'],
    properties: [
        new OA\Property(property: 'from_bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'to_bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'amount', type: 'string', example: '1500.00'),
        new OA\Property(property: 'transfer_date', type: 'string', format: 'date'),
        new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'idempotency_key', description: 'Client key: a retry with the same key returns the transfer already recorded', type: 'string', maxLength: 100),
    ]
)]
final class StoreTransferRequest extends FormRequest
{
    use TenantScopedRules;

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
            'from_bank_account_id' => ['required', 'uuid', $this->tenantOwns('bank_accounts')],
            'to_bank_account_id' => ['required', 'uuid', 'different:from_bank_account_id', $this->tenantOwns('bank_accounts')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
