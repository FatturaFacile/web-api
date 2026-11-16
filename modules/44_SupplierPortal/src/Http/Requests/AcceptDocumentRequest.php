<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Acceptance of a supplier document, optionally completing the data read from the file.
 */
final class AcceptDocumentRequest extends FormRequest
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
        return [
            'reference' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
        ];
    }
}
