<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DetectBezugsteuerRequest extends FormRequest
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
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
        ];
    }
}
