<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ItalianVat\Services\VatSectionService;

/**
 * Creates a sectional of a VAT register.
 */
final class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'register_type' => ['required', Rule::in(VatSectionService::REGISTER_TYPES)],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('it_vat_sections', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'name' => ['required', 'string', 'max:120'],
            'document_kind' => ['nullable', Rule::in(VatSectionService::DOCUMENT_KINDS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
