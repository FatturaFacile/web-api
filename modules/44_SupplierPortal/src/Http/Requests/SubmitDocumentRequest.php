<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A document entered by the staff on behalf of a supplier (multipart when a file is attached).
 */
final class SubmitDocumentRequest extends FormRequest
{
    use TenantScopedRules;

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
        return [
            'supplier_id' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'requirement_id' => ['required', 'uuid', $this->tenantOwns('sp_document_requirements')],
            'reference' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('supplier_portal.upload_max_kilobytes')],
        ];
    }
}
