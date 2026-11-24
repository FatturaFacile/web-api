<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\Incoterm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomsValueRequest extends FormRequest
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
            'incoterm' => ['required', Rule::enum(Incoterm::class)],
            'goods_value' => ['required', 'numeric', 'min:0'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'insurance' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
