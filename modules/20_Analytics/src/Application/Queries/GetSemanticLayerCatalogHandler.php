<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use Modules\Analytics\Calculation\CalculatedFields;
use Modules\Analytics\Calculation\FormulaParser;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Pivot\PivotDefinition;
use Modules\Analytics\Semantic\Dimension;
use Modules\Analytics\Semantic\ReportSpec;
use Modules\Analytics\Semantic\SemanticCatalog;
use Modules\Analytics\Services\ReportExportFormatter;

/**
 * The governed catalog for the report studio. Datasets come from the semantic layer already limited to the modules the
 * user can read and, inside them, to the fields their access level allows (a role without write access never sees a cost
 * or a margin). The options are the allow-lists the rest of the module enforces, so a front end built from this answer can
 * only ask for what the API accepts.
 */
final readonly class GetSemanticLayerCatalogHandler
{
    public function __construct(private SemanticCatalog $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetSemanticLayerCatalogQuery $query): array
    {
        return [
            'datasets' => array_values(array_map(static fn ($dataset): array => $dataset->toArray(), $this->catalog->readableBy($query->user))),
            'options' => [
                'filter_logic' => ['and', 'or'],
                'filter_max_depth' => ReportSpec::MaxFilterTreeDepth,
                'measure_operators' => ReportSpec::MeasureOperators,
                'granularities' => Dimension::Granularities,
                'period_presets' => ReportSpec::Presets,
                'max_dimensions' => ReportSpec::MaxDimensions,
                'max_measures' => ReportSpec::MaxMeasures,
                'formats' => ReportExportFormatter::Formats,
                'locales' => RegionalReportFormatter::Locales,
                'chart_types' => PivotDefinition::ChartTypes,
                'formatting_operators' => PivotDefinition::FormattingOperators,
                'background_tokens' => PivotDefinition::BackgroundTokens,
                'text_tokens' => PivotDefinition::TextTokens,
                'calculated' => ['functions' => array_keys(FormulaParser::Functions), 'formats' => CalculatedFields::Formats, 'max_fields' => CalculatedFields::MaxFields],
                'visibilities' => DesignedReport::Visibilities,
                'delivery_channels' => ['email', 'telegram', 'dms', 'webhook', 'sftp'],
                'schedule_modes' => ExportSchedule::Frequencies,
            ],
        ];
    }
}
