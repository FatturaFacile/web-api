<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class Gs1ParseRequest extends FormRequest
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
            'barcode' => ['required', 'string', 'max:255'],
        ];
    }
}
