<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A qualification document of a supplier.
 */
final class StoreSupplierDocumentRequest extends FormRequest
{
    use TenantScopedRules;

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
            'document_type' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:150'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'attachment_id' => ['nullable', 'uuid', $this->tenantOwns('attachments')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
