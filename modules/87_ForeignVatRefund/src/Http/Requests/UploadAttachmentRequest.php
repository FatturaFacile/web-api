<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The scan of an original invoice or receipt, or a mandate document.
 */
final class UploadAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,tif,tiff', 'max:'.(int) config('foreign_vat_refund.max_upload_kb', 10240)],
            'type' => ['sometimes', 'in:power_of_attorney,certificate'],
            'certificate_expiry_date' => ['nullable', 'date'],
        ];
    }
}
