<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MissionReportRequest extends FormRequest
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
            'answers' => ['required', 'array', 'max:24'],
            'answers.*' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * Point numbers of the mission report, from 1 to 24.
     *
     * @return array<string, ?string>
     */
    public function answers(): array
    {
        return array_filter((array) $this->validated('answers'), static fn (mixed $value, mixed $key): bool => is_numeric($key) && (int) $key >= 1 && (int) $key <= 24, ARRAY_FILTER_USE_BOTH);
    }
}
