<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class StartAuditRequest extends FormRequest
{
    use ScopesToTenant;

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
            'store_id' => ['required', 'uuid', $this->tenantExists('frn_stores')],
            'template_id' => ['required', 'uuid', $this->tenantOrGlobalExists('frn_audit_templates')],
            'audited_on' => ['nullable', 'date'],
        ];
    }
}
