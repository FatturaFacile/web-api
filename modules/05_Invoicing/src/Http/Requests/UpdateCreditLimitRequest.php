<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCreditLimitRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
        ];
    }
}
