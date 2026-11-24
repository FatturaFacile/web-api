<?php

declare(strict_types=1);

namespace App\Http\Requests\Retail;

use Illuminate\Foundation\Http\FormRequest;

class ClosePosSessionRequest extends FormRequest
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
            'closing_cash' => ['required', 'numeric', 'min:0'],
        ];
    }
}
