<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class UploadPassiveInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Guard against excessive request content lengths before processing.
     */
    protected function prepareForValidation(): void
    {
        $contentLength = (int) $this->header('Content-Length', 0);
        if ($contentLength > 10 * 1024 * 1024) {
            abort(413, 'Payload Too Large');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimetypes:text/xml,application/xml,text/plain'],
        ];
    }
}
