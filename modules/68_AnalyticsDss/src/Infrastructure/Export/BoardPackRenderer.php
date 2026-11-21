<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Export;

use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Modules\Analytics\Export\RegionalReportFormatter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Renders the Executive Board Briefing Pack from the DSS read models: a PDF (scorecard, alerts, variance bridge and the top
 * and bottom of the profitability matrix) or an XLSX workbook with one sheet per section. Labels come from the language files
 * `analytics_dss.board_pack.*` in the requested language; numbers and dates follow the regional format of the locale.
 */
final class BoardPackRenderer
{
    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, mixed>  $bridge
     * @param  array<string, mixed>  $matrix
     */
    public function pdf(array $overview, array $bridge, array $matrix, string $locale, string $tenantName): string
    {
        $regional = new RegionalReportFormatter($locale);
        $t = fn (string $key, array $replace = []): string => e((string) trans("analytics_dss.board_pack.{$key}", $replace, $regional->language()));
        $currency = (string) $overview['meta']['currency'];
        $number = static fn (mixed $v): string => $v === null ? '–' : e($regional->number((string) $v));

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1f2933}h1{font-size:18px;margin:0}h2{font-size:13px;margin:16px 0 6px;border-bottom:1px solid #cbd2d9}table{width:100%;border-collapse:collapse}th,td{padding:3px 5px;border-bottom:1px solid #e4e7eb;text-align:right}th:first-child,td:first-child{text-align:left}th{background:#f0f4f8}.neg{color:#b8321f}.muted{color:#7b8794}</style></head><body>';
        $html .= '<h1>'.$t('title').'</h1><p class="muted">'.e($tenantName).' · '.$t('period').': '.e($regional->date((string) $overview['meta']['period']['from'])).' – '.e($regional->date((string) $overview['meta']['period']['to'])).' · '.$t('currency').': '.e($currency).'</p>';

        $html .= '<h2>'.$t('scorecard').'</h2><table><tr><th>'.$t('kpi').'</th><th>'.$t('value').'</th><th>'.$t('comparison').'</th><th>Δ%</th></tr>';

        foreach ($overview['scorecard'] as $kpis) {
            foreach ($kpis as $kpi) {
                $delta = $kpi['delta_pct'];
                $html .= '<tr><td>'.$t('kpi_'.$kpi['key']).'</td><td>'.$number($kpi['value']).'</td><td>'.$number($kpi['comparison_value']).'</td><td class="'.($kpi['favorable'] === false ? 'neg' : '').'">'.($delta === null ? '–' : e($regional->number((string) $delta)).'%').'</td></tr>';
            }
        }

        $html .= '</table><h2>'.$t('alerts').'</h2>';
        $html .= $overview['alerts'] === [] ? '<p class="muted">'.$t('no_alerts').'</p>' : '<table><tr><th>'.$t('alert').'</th><th>'.$t('severity').'</th><th>'.$t('value').'</th></tr>';

        foreach ($overview['alerts'] as $alert) {
            $html .= '<tr><td>'.$t('alert_'.$alert['code']).'</td><td>'.e((string) $alert['severity']).'</td><td>'.$number($alert['value']).'</td></tr>';
        }

        $html .= ($overview['alerts'] === [] ? '' : '</table>').'<h2>'.$t('ebitda_bridge').'</h2><table><tr><th>'.$t('step').'</th><th>'.$t('value').'</th><th>'.$t('cumulative').'</th></tr>';

        foreach ($bridge['ebitda_bridge'] as $step) {
            $html .= '<tr><td>'.$t('step_'.$step['key']).'</td><td class="'.($step['value'] < 0 ? 'neg' : '').'">'.$number($step['value']).'</td><td>'.$number($step['cumulative']).'</td></tr>';
        }

        $html .= '</table><h2>'.$t('profitability', ['dimension' => $t('dimension_'.$matrix['dimension'])]).'</h2><table><tr><th>'.$t('entity').'</th><th>'.$t('net_revenue').'</th><th>'.$t('gross_margin').'</th><th>'.$t('net_operating_profit').'</th></tr>';

        foreach (array_merge(array_slice($matrix['rows'], 0, 5), array_slice($matrix['rows'], -5)) as $row) {
            $html .= '<tr><td>'.e((string) $row['label']).'</td><td>'.$number($row['net_revenue']).'</td><td>'.$number($row['gross_margin']).'</td><td class="'.($row['net_operating_profit'] < 0 ? 'neg' : '').'">'.$number($row['net_operating_profit']).'</td></tr>';
        }

        $html .= '</table><p class="muted">'.$t('generated').': '.e($regional->dateTime(new DateTimeImmutable)).'</p></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * Writes the workbook to `$path`.
     *
     * @param  array<string, mixed>  $overview
     * @param  array<string, mixed>  $bridge
     * @param  array<string, mixed>  $matrix
     */
    public function xlsx(string $path, array $overview, array $bridge, array $matrix, string $locale): void
    {
        $language = (new RegionalReportFormatter($locale))->language();
        $t = static fn (string $key): string => (string) trans("analytics_dss.board_pack.{$key}", [], $language);
        $bold = (new Style)->withFontBold(true)->withBackgroundColor('E7E6E6');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr($t('scorecard'), 0, 31));
        $writer->addRow(Row::fromValuesWithStyle([$t('kpi'), $t('value'), $t('comparison'), 'Δ', 'Δ%'], $bold));

        foreach ($overview['scorecard'] as $kpis) {
            foreach ($kpis as $kpi) {
                $writer->addRow(Row::fromValues([$t('kpi_'.$kpi['key']), $kpi['value'], $kpi['comparison_value'], $kpi['delta'], $kpi['delta_pct']]));
            }
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('alerts'), 0, 31));
        $writer->addRow(Row::fromValuesWithStyle([$t('alert'), $t('severity'), $t('value')], $bold));

        foreach ($overview['alerts'] as $alert) {
            $writer->addRow(Row::fromValues([$t('alert_'.$alert['code']), $alert['severity'], $alert['value']]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('ebitda_bridge'), 0, 31));
        $writer->addRow(Row::fromValuesWithStyle([$t('step'), $t('value'), $t('cumulative')], $bold));

        foreach ($bridge['ebitda_bridge'] as $step) {
            $writer->addRow(Row::fromValues([$t('step_'.$step['key']), $step['value'], $step['cumulative']]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t('dimension_'.$matrix['dimension']), 0, 31));
        $writer->addRow(Row::fromValuesWithStyle([$t('entity'), $t('net_revenue'), $t('gross_margin'), 'CM I', 'CM II', 'EBITDA', $t('net_operating_profit'), '%'], $bold));

        foreach ($matrix['rows'] as $row) {
            $writer->addRow(Row::fromValues([CsvFormulaNeutralizer::neutralize((string) $row['label']), $row['net_revenue'], $row['gross_margin'], $row['contribution_margin_1'], $row['contribution_margin_2'], $row['ebitda'], $row['net_operating_profit'], $row['net_margin_pct']]));
        }

        $writer->close();
    }
}
