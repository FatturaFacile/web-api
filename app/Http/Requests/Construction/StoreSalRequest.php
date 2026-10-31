<?php

declare(strict_types=1);

namespace App\Http\Requests\Construction;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalRequest extends FormRequest
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
            'construction_site_id' => ['required', 'uuid'],
            'sal_number' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'gross_certified_amount' => ['required', 'numeric', 'min:0'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ];
    }
}
