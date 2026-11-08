<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An accrual policy of a leave type. source_reference records where the entitlement comes from (contract clause,
 * collective agreement article, law): the application does not supply statutory values.
 */
final class SaveLeaveAccrualPolicyRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $unique = Rule::unique('hr_leave_accrual_policies', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'leave_type_id' => [$required, 'uuid', TenantScopedRules::exists('hr_leave_types')],
            'code' => [$required, 'string', 'max:40', $unique],
            'name' => [$required, 'string', 'max:150'],
            'employment_type' => ['sometimes', 'nullable', Rule::in(['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal'])],
            'annual_entitlement_hours' => [$required, 'decimal:0,2', 'min:0', 'max:9999'],
            'accrual_frequency' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'prorate_by_fte' => ['sometimes', 'boolean'],
            'max_balance_hours' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999'],
            'max_carryover_hours' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999'],
            'valid_from' => [$required, 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
