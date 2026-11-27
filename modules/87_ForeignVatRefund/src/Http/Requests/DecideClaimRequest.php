<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideClaimRequest extends FormRequest
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
            'approved_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
