<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use App\Rules\PngSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignQuoteOnlineRequest extends FormRequest
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
        $drawn = $this->input('signature_type') === 'drawn';

        return [
            'signer_name' => ['required', 'string', 'max:150'],
            'signer_role' => ['required', 'string', 'max:150'],
            'purchase_order_number' => ['nullable', 'string', 'max:100'],
            'signature_type' => ['required', Rule::in(['drawn', 'typed'])],
            'signature_data' => $drawn ? ['required', 'string', new PngSignature] : ['required', 'string', 'max:150'],
            'selected_optional_item_ids' => ['nullable', 'array'],
            'selected_optional_item_ids.*' => ['uuid'],
            'accept_terms' => ['accepted'],
        ];
    }
}
