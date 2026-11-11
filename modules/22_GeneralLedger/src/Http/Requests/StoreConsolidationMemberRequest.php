<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\ConsolidationMethod;

/**
 * Adds a member to a group, or changes its share and method (then `member_tenant_id` is not read).
 */
final class StoreConsolidationMemberRequest extends FormRequest
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
            'member_tenant_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'ownership_percentage' => ['required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4'],
            'consolidation_method' => ['required', Rule::enum(ConsolidationMethod::class)],
            'holder_tenant_id' => ['nullable', 'uuid'],
            'fiscal_year_offset_months' => ['nullable', 'integer', 'between:-11,11'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ];
    }
}
