<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateConsolidationGroupRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'name' => ['sometimes', 'string', 'max:190', Rule::unique('gl_consolidation_groups', 'name')->ignore($this->route('id'))->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'group_currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'translation_reserve_code' => ['sometimes', 'string', 'max:40'],
            'consolidation_difference_code' => ['sometimes', 'string', 'max:40'],
            'equity_method_result_code' => ['sometimes', 'string', 'max:40'],
            'equity_method_reserve_code' => ['sometimes', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
