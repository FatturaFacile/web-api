<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of a bank statement file: CBI (`.cbi`, `.txt`) or ISO 20022 (`.xml`).
 */
class ImportBankStatementRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'extensions:cbi,txt,xml'],
            'auto_reconcile' => ['sometimes', 'boolean'],
        ];
    }
}
