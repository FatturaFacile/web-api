<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyDsarIdentityRequest extends FormRequest
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
        return ['method' => ['required', 'string', 'max:80']];
    }
}
