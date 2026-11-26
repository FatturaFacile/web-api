<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "What would happen to a document with these attributes?" Read-only: nothing is saved.
 */
final class SimulateMatrixRequest extends FormRequest
{
    /**
     * Every member of the workflow module may simulate.
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
        return [
            'subject_type' => ['required', 'string', 'max:255'],
            'matrix_id' => ['nullable', 'uuid', Rule::exists('approval_matrixes', 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'attributes' => ['required', 'array'],
            'attributes.amount' => ['nullable', 'numeric'],
            'attributes.amount_functional' => ['nullable', 'numeric'],
            'attributes.currency' => ['nullable', 'string', 'size:3'],
            'attributes.cost_center_id' => ['nullable', 'string', 'max:64'],
            'attributes.category' => ['nullable', 'string', 'max:100'],
            'attributes.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'attributes.company_id' => ['nullable', 'string', 'max:64'],
            'attributes.new_supplier' => ['nullable', 'boolean'],
        ];
    }
}
