<?php

declare(strict_types=1);

namespace App\Http\Requests\Automotive;

use Illuminate\Foundation\Http\FormRequest;

class CompleteInspectionRequest extends FormRequest
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
            'result' => ['required', 'in:passed,failed,conditional'],
            'certificate_number' => ['nullable', 'string'],
            'inspector_notes' => ['nullable', 'string'],
        ];
    }
}
