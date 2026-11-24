<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

final class ExtendDsarRequest extends FormRequest
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
            'days' => ['required', 'integer', 'min:1', 'max:60'],
            'justification' => ['required', 'string', 'max:2000'],
        ];
    }
}
