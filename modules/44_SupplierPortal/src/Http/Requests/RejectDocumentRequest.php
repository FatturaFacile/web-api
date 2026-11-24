<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejection of a supplier document with the reason the supplier sees.
 */
final class RejectDocumentRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
