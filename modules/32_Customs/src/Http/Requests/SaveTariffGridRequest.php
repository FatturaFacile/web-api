<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveTariffGridRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'service_type' => [$required, 'string', 'max:40'],
            'currency' => [$required, 'string', 'size:3'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
