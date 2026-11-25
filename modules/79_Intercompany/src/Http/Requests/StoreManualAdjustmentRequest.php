<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreManualAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:2', 'max:120'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.entity_tenant_id' => ['nullable', 'uuid'],
            'lines.*.account_code' => ['required', 'string', 'max:40'],
            'lines.*.account_name' => ['nullable', 'string', 'max:190'],
            'lines.*.account_type' => ['required', Rule::in(['asset', 'wip', 'liability', 'equity', 'revenue', 'cogs', 'expense'])],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
