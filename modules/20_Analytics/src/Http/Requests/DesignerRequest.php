<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        ];
    }
}
