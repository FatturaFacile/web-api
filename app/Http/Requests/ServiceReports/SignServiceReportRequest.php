<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceReports;

use App\Rules\PngSignature;
use Illuminate\Foundation\Http\FormRequest;

class SignServiceReportRequest extends FormRequest
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
            'signature_base64' => ['required', 'string', new PngSignature],
            'signer_name' => ['required', 'string', 'max:150'],
            'signer_role' => ['nullable', 'string', 'max:100'],
            'signer_email' => ['nullable', 'email', 'max:150'],
        ];
    }
}
