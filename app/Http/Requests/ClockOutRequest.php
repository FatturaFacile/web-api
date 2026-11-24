<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClockOutRequest extends FormRequest
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
            'clock_out' => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
