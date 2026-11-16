<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A document a supplier sends from the portal: the file is required.
 */
final class PortalDocumentRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * The portal token authenticates the supplier.
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
            'requirement_id' => ['required', 'uuid', $this->tenantOwns('sp_document_requirements')],
            'reference' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('supplier_portal.upload_max_kilobytes')],
        ];
    }
}
