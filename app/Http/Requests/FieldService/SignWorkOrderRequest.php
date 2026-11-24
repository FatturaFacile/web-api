<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldService;

use App\Rules\PngSignature;
use Illuminate\Foundation\Http\FormRequest;

class SignWorkOrderRequest extends FormRequest
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
            'signed_by' => ['required', 'string', 'max:100'],
            'signature_base64' => ['required', 'string', new PngSignature],
        ];
    }
}
