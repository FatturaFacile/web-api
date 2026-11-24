<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * A liquidity forecast scenario: delays and growth factor applied to the expected flows.
 */
#[OA\Schema(
    schema: 'TreasuryStoreForecastScenarioRequest',
    required: ['code', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 50, example: 'PESSIMISTIC'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'collection_delay_days', description: 'Customers pay this many days later', type: 'integer', minimum: -365, maximum: 365),
        new OA\Property(property: 'disbursement_acceleration_days', description: 'Suppliers are paid this many days earlier', type: 'integer', minimum: -365, maximum: 365),
        new OA\Property(property: 'sales_growth_factor', description: 'Multiplier of the expected inflows (0.8 = 80%)', type: 'number', maximum: 5, minimum: 0),
    ]
)]
final class StoreForecastScenarioRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', Rule::unique('forecast_scenarios', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'collection_delay_days' => ['sometimes', 'integer', 'between:-365,365'],
            'disbursement_acceleration_days' => ['sometimes', 'integer', 'between:-365,365'],
            'sales_growth_factor' => ['sometimes', 'numeric', 'between:0,5', 'decimal:0,4'],
        ];
    }
}
