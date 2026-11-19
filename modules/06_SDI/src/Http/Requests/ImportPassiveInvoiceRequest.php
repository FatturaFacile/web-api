<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportPassiveInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // .p7m files have no registered MIME type, so the extension is what counts.
            'file' => ['required', 'file', 'max:10240', 'extensions:xml,p7m'],
            'preview_only' => ['sometimes', 'boolean'],
        ];
    }
}
