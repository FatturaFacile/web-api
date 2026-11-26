<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;

final class RunStudioExportRequest extends FormRequest
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
            'format_code' => ['required', 'string', Rule::in(array_map(static fn (StudioExportFormat $f): string => $f->value, StudioExportFormat::cases()))],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'fiscal_year_id' => ['nullable', 'uuid'],
            'fiscal_period_id' => ['nullable', 'uuid'],
            'only_verified' => ['nullable', 'boolean'],
            'apply_transcoding' => ['nullable', 'boolean'],
            'strict_mapping' => ['nullable', 'boolean'],
            'consultant_number' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'client_number' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'account_length' => ['nullable', 'integer', 'min:4', 'max:8'],
        ];
    }
}
