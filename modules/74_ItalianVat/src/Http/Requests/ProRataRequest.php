<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Operations of a year that give the pro-rata percentage.
 */
final class ProRataRequest extends FormRequest
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
            'deductible_operations' => ['required', 'numeric', 'min:0'],
            'total_operations' => ['required', 'numeric', 'min:0'],
            'definitive' => ['nullable', 'boolean'],
        ];
    }
}
