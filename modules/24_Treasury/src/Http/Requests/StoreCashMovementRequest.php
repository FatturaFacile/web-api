<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\CashMovement;
use OpenApi\Attributes as OA;

/**
 * A receipt or payment of a cash register, in cash or by cheque, against a counter account of the chart.
 */
#[OA\Schema(
    schema: 'TreasuryStoreCashMovementRequest',
    required: ['movement_date', 'direction', 'amount', 'counter_account_id', 'description', 'idempotency_key'],
    properties: [
        new OA\Property(property: 'movement_date', type: 'string', format: 'date'),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'cheque'], nullable: true),
        new OA\Property(property: 'amount', type: 'string', example: '45.90'),
        new OA\Property(property: 'counter_account_id', description: 'Counter account (chart of accounts)', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string', maxLength: 255),
        new OA\Property(property: 'reference', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'idempotency_key', description: 'Client key: a retry with the same key returns the movement already recorded', type: 'string', maxLength: 100),
    ]
)]
final class StoreCashMovementRequest extends FormRequest
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
            'movement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'direction' => ['required', Rule::in([CashMovement::In, CashMovement::Out])],
            'payment_method' => ['nullable', Rule::in(['cash', 'cheque'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'counter_account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
