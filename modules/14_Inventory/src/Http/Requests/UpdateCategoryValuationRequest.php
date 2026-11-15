<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\ValuationMethod;

final class UpdateCategoryValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $account = fn (): array => [
            'sometimes',
            'nullable',
            'uuid',
            Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId),
        ];

        return [
            'valuation_method' => ['required', 'string', Rule::in(ValuationMethod::acceptedInputValues())],
            'inventory_account_id' => $account(),
            'cogs_account_id' => $account(),
            'ppv_account_id' => $account(),
            'landed_cost_clearing_account_id' => $account(),
        ];
    }
}
