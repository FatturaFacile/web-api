<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Analytics\Semantic\ReportSpec;

/**
 * The filters a viewer applies on top of a dashboard (they replace the saved ones), and the drill-down of a widget.
 */
final class DashboardDataRequest extends BiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::filterRules(''),
            'drill' => ['nullable', 'array', 'max:6'],
            'drill.*' => ['array'],
            'drill.*.dimension' => ['required', 'string', 'max:41'],
            'drill.*.value' => ['nullable'],
        ];
    }

    /**
     * Rules of a period and of a list of dimension filters, under a prefix.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(string $prefix): array
    {
        return [
            "{$prefix}period" => ['nullable', 'array'],
            "{$prefix}period.preset" => ['nullable', Rule::in(ReportSpec::Presets)],
            "{$prefix}period.from" => ['nullable', 'date_format:Y-m-d', "required_with:{$prefix}period.to"],
            "{$prefix}period.to" => ['nullable', 'date_format:Y-m-d', "required_with:{$prefix}period.from", "after_or_equal:{$prefix}period.from"],
            "{$prefix}filters" => ['nullable', 'array', 'max:10'],
            "{$prefix}filters.*" => ['array'],
            "{$prefix}filters.*.field" => ['required', 'string', 'max:41'],
            "{$prefix}filters.*.operator" => ['required', 'string', 'max:20'],
            "{$prefix}filters.*.value" => ['nullable'],
        ];
    }
}
