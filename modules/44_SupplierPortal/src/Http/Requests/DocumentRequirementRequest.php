<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A required supplier document type, on creation (POST) or change (PUT).
 */
final class DocumentRequirementRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique('sp_document_requirements', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($this->route('id'))->whereNull('deleted_at')],
            'name' => [$presence, 'string', 'max:150'],
            'country' => ['nullable', 'string', 'size:2', 'in:IT,CH'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'requires_expiry' => ['sometimes', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'alert_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
