<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveServiceDiscountRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid', CustomsRules::exists('contacts')],
            'service_type' => ['required', 'string', 'max:40'],
            'discount_percent' => ['required', 'numeric', 'between:0,100'],
        ];
    }
}
