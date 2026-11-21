<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates uploading a technical specification, drawing, or BOM attachment to an RFQ.
 */
final class StoreRfqAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,dwg,dxf,step,stp,zip'],
            'is_visible_to_suppliers' => ['nullable', 'boolean'],
        ];
    }
}
