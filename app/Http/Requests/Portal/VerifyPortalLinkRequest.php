<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPortalLinkRequest extends FormRequest
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
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'otp' => ['nullable', 'string', 'digits:6'],
            'accept_privacy' => ['nullable', 'boolean'],
        ];
    }
}
