<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Semantic\SemanticCatalog;
use Modules\Analytics\Services\ReportExportFormatter;

/**
 * An ad-hoc run or export of a report that is not saved. The specification is validated by the report engine.
 */
final class DesignerRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the report engine.
     */
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
            'dataset' => ['required', 'string', Rule::in(array_keys(app(SemanticCatalog::class)->all()))],
            'spec' => ['required', 'array'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('analytics.reporting.max_per_page', 500)],
            'format' => ['nullable', Rule::in(ReportExportFormatter::Formats)],
            'locale' => ['nullable', Rule::in(RegionalReportFormatter::Locales)],
            'pivot' => ['nullable', 'array'],
            'calculated_fields' => ['nullable', 'array', 'max:10'],
            'conditional_formatting' => ['nullable', 'array', 'max:20'],
            'chart_config' => ['nullable', 'array'],
        ];
    }

    /**
     * The definition in the shape the plan and the handlers take.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dataset' => (string) $this->input('dataset'),
            'spec' => (array) $this->input('spec'),
            'pivot' => (array) $this->input('pivot', []),
            'calculated_fields' => (array) $this->input('calculated_fields', []),
            'conditional_formatting' => (array) $this->input('conditional_formatting', []),
            'chart_config' => (array) $this->input('chart_config', []),
        ];
    }
}
