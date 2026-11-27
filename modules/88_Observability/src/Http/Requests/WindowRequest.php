<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Time window (hours) and size (limit) of the diagnostic lists of the platform administrator.
 */
final class WindowRequest extends FormRequest
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
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function hours(int $default = 24): int
    {
        return (int) $this->validated('hours', $default);
    }

    public function limit(int $default = 20): int
    {
        return (int) $this->validated('limit', $default);
    }
}
