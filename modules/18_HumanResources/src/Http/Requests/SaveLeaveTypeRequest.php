<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An absence (leave) type of the company. The yearly entitlement is company data (contract, collective agreement or
 * law as the company applies it); nothing is assumed.
 */
final class SaveLeaveTypeRequest extends FormRequest
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
        $unique = Rule::unique('hr_leave_types', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:60', $unique],
            'name' => [$required, 'string', 'max:150'],
            'category' => [$required, Rule::in(['vacation', 'sick', 'maternity', 'paternity', 'parental', 'unpaid', 'rol', 'study', 'bereavement', 'military', 'accident', 'other'])],
            'is_paid' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'annual_entitlement_hours' => [$required, 'decimal:0,2', 'min:0', 'max:9999'],
            'accrual_frequency' => ['sometimes', Rule::in(['monthly', 'yearly', 'none'])],
            'max_carryover_hours' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
