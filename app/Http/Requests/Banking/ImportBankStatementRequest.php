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
