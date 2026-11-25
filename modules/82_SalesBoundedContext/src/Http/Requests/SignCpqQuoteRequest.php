<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;

final class SignCpqQuoteRequest extends FormRequest
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
            'signature_standard' => ['nullable', Rule::in(ElectronicSignatureStandard::values())],
            'signer_name' => ['required', 'string', 'max:120'],
            'signer_email' => ['required', 'email', 'max:180'],
            'certificate_serial' => ['nullable', 'string', 'max:80'],
        ];
    }
}
