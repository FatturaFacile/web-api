<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitClaimRequest extends FormRequest
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
            'protocol_number' => ['nullable', 'string', 'max:80'],
            'submitted_on' => ['nullable', 'date'],
        ];
    }
}
