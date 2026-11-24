<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An overtime rule of the company. Threshold and premium come from the law or the collective agreement the company
 * applies; source_reference records which one.
 */
final class SaveOvertimeRuleRequest extends FormRequest
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
        $unique = Rule::unique('hr_overtime_rules', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        if ($this->route('id') !== null) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:40', $unique],
            'name' => [$required, 'string', 'max:150'],
            'basis' => [$required, Rule::in(['daily', 'weekly'])],
            'threshold_minutes' => [$required, 'integer', 'min:0', 'max:10080'],
            'premium_percent' => [$required, 'decimal:0,2', 'min:0', 'max:500'],
            'wage_type_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
