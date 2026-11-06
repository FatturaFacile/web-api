<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveMasterDataRequest extends FormRequest
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
            'code' => [$required, 'string', 'max:12'],
            'tariff_code' => [$this->route('type') === 'statistical-keys' ? $required : 'prohibited', 'string', 'max:12'],
            'name_de' => ['sometimes', 'nullable', 'string', 'max:500'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:500'],
            'name_it' => ['sometimes', 'nullable', 'string', 'max:500'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
