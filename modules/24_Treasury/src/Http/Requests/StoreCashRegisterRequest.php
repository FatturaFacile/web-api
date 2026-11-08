<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * A cash register on a ledger account of the tenant chart.
 */
#[OA\Schema(
    schema: 'TreasuryStoreCashRegisterRequest',
    required: ['code', 'name', 'currency', 'gl_account_id'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3),
        new OA\Property(property: 'gl_account_id', description: 'Ledger account of the cash (chart of accounts)', type: 'string', format: 'uuid'),
        new OA\Property(property: 'opening_balance', type: 'string', example: '250.00', nullable: true),
    ]
)]
final class StoreCashRegisterRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:30', Rule::unique('treasury_cash_registers', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'gl_account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
        ];
    }
}
