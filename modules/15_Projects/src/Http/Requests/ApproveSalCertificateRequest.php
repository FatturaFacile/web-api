<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer approval of a SAL certificate and the VAT rate of its invoice (Mod 081).
 */
final class ApproveSalCertificateRequest extends FormRequest
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
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'approved_by' => ['nullable', 'string', 'max:150'],
        ];
    }
}
