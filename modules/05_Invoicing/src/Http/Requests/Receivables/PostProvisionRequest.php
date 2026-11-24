<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Books the prudential provision to the general ledger at a reference date (the company today when omitted).
 */
final class PostProvisionRequest extends FormRequest
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
            'as_of' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
