<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Prepaid hours or amount received into the retainer of a project (Mod 081).
 */
final class TopUpPrepaidRetainerRequest extends FormRequest
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
            'hours' => ['nullable', 'numeric', 'gte:0', 'max:99999999'],
            'amount' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
