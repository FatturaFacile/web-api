<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updating supplier visibility on an RFQ attachment.
 */
final class UpdateRfqAttachmentRequest extends FormRequest
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
            'is_visible_to_suppliers' => ['required', 'boolean'],
        ];
    }
}
