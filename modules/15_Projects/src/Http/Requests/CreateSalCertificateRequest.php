<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A SAL progress certificate stated by the cumulative completion percentage of the work (Mod 081).
 */
final class CreateSalCertificateRequest extends FormRequest
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
            'period_start_date' => ['required', 'date_format:Y-m-d'],
            'period_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start_date'],
            'cumulative_completion_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
