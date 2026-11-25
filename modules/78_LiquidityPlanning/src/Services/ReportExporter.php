<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Renders the board and bank report (see ReportService) as an .xlsx workbook for investors and management committees, and as
 * a PDF, with the labels of the requested locale.
 */
final class ReportExporter
{
    /**
     * @param  array<string, mixed>  $report
     * @return string the binary content of the workbook
     */
    public function xlsx(array $report, string $locale): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lpxl');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the liquidity workbook could not be created.');
        }

        try {
            $t = static fn (string $key, array $params = []): string => LiquidityTexts::get($key, $locale, $params);
            $overview = $report['overview'];
            $hasScenario = $overview['scenario'] !== null;
            $writer = new Writer;
            $writer->openToFile($path);

            $writer->getCurrentSheet()->setName($t('lpApi.export.sheet.summary'));
            $writer->addRow(Row::fromValues([$t('lpApi.export.title'), CsvFormulaNeutralizer::neutralize((string) $report['company']['name']), $t('lpApi.export.as_of'), $report['as_of'], $t('lpApi.export.currency'), $report['currency']]));
            $writer->addRow(Row::fromValues([$t('lpApi.export.opening_cash'), (float) $overview['opening_cash']]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$t('lpApi.export.horizon'), $t('lpApi.export.inflows'), $t('lpApi.export.outflows'), $t('lpApi.export.net'), $t('lpApi.export.closing'), $t('lpApi.export.min_balance'), ...($hasScenario ? [$t('lpApi.export.scenario_closing'), $t('lpApi.export.scenario_min_balance')] : [])]));

            foreach ($overview['horizons'] as $horizon) {
                $base = $horizon['baseline'];
                $scn = $horizon['scenario'];
                $writer->addRow(Row::fromValues([$horizon['days'], (float) $base['inflows'], (float) $base['outflows'], (float) $base['net'], (float) $base['closing_balance'], (float) $base['min_balance'], ...($hasScenario && $scn !== null ? [(float) $scn['closing_balance'], (float) $scn['min_balance']] : [])]));
            }

            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$t('lpApi.export.indicator'), $t('lpApi.export.baseline'), ...($hasScenario ? [$t('lpApi.export.scenario')] : [])]));

            foreach ($this->indicatorRows($overview['indicators']['baseline'], $overview['indicators']['scenario'], $t) as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->addRow(Row::fromValues([]));

            foreach ($report['eba']['sections'] as $section) {
                $writer->addRow(Row::fromValues([$t($section['title_key']), $t('lpApi.status.'.$section['status']), $t($section['summary_key'], $section['params'])]));
            }

            foreach (['daily' => 'daily', 'weekly' => 'weekly'] as $series => $sheet) {
                $writer->addNewSheetAndMakeItCurrent()->setName($t('lpApi.export.sheet.'.$sheet));
                $writer->addRow(Row::fromValues([$t('lpApi.export.date'), $t('lpApi.export.inflows'), $t('lpApi.export.outflows'), $t('lpApi.export.net'), $t('lpApi.export.closing'), $t('lpApi.tier.certain'), $t('lpApi.tier.probable'), $t('lpApi.tier.estimated'), ...($hasScenario ? [$t('lpApi.export.scenario_closing')] : [])]));

                foreach ($overview['baseline'][$series] as $index => $point) {
                    $net = static fn (string $tier): float => (float) $point['tiers'][$tier]['inflows'] - (float) $point['tiers'][$tier]['outflows'];
                    $writer->addRow(Row::fromValues([$point['date'], (float) $point['inflows'], (float) $point['outflows'], (float) $point['net'], (float) $point['closing'], $net('certain'), $net('probable'), $net('estimated'), ...($hasScenario ? [(float) $overview['scenario'][$series][$index]['closing']] : [])]));
                }
            }

            $writer->addNewSheetAndMakeItCurrent()->setName($t('lpApi.export.sheet.dscr'));
            $writer->addRow(Row::fromValues([$t('lpApi.export.date'), $t('lpApi.export.kind'), $t('lpApi.export.category'), $t('lpApi.export.tier'), $t('lpApi.export.description'), $t('lpApi.export.amount')]));

            foreach ($overview['indicators']['baseline']['dscr']['lines'] as $line) {
                $writer->addRow(Row::fromValues([$line['date'], $t('lpApi.dscr_kind.'.$line['kind']), $t('lpApi.category.'.$line['category']), $t('lpApi.tier.'.$line['tier']), CsvFormulaNeutralizer::neutralize((string) ($line['label'] ?? '')), (float) $line['amount']]));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName($t('lpApi.export.sheet.alerts'));
            $writer->addRow(Row::fromValues([$t('lpApi.export.severity'), $t('lpApi.export.status'), $t('lpApi.export.title'), $t('lpApi.export.description'), $t('lpApi.export.date')]));

            foreach ($overview['alerts'] as $alert) {
                $writer->addRow(Row::fromValues([$t('lpApi.severity.'.$alert['severity']), $t('lpApi.alert_status.'.$alert['status']), $t($alert['title_key']), $t($alert['message_key'], $alert['params']), $alert['detected_at']]));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return string the binary content of the PDF
     */
    public function pdf(array $report, string $locale): string
    {
        return Pdf::loadHTML($this->html($report, $locale))->setPaper('a4', 'portrait')->output();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function html(array $report, string $locale): string
    {
        $t = static fn (string $key, array $params = []): string => e(LiquidityTexts::get($key, $locale, $params));
        $money = static fn (string|int|float $amount): string => number_format((float) $amount, 2, '.', "'");
        $overview = $report['overview'];
        $hasScenario = $overview['scenario'] !== null;
        $rows = '';

        foreach ($overview['horizons'] as $horizon) {
            $base = $horizon['baseline'];
            $rows .= '<tr><td>'.(int) $horizon['days'].'</td><td class="n">'.$money($base['inflows']).'</td><td class="n">'.$money($base['outflows']).'</td><td class="n">'.$money($base['net']).'</td><td class="n">'.$money($base['closing_balance']).'</td><td class="n">'.$money($base['min_balance']).'</td>'
                .($hasScenario && $horizon['scenario'] !== null ? '<td class="n">'.$money($horizon['scenario']['closing_balance']).'</td>' : '').'</tr>';
        }

        $indicators = '';

        foreach ($this->indicatorRows($overview['indicators']['baseline'], $overview['indicators']['scenario'], static fn (string $key, array $params = []): string => LiquidityTexts::get($key, $locale, $params)) as $row) {
            $indicators .= '<tr>'.implode('', array_map(static fn (mixed $cell): string => '<td>'.e((string) $cell).'</td>', $row)).'</tr>';
        }

        $sections = '';

        foreach ($report['eba']['sections'] as $section) {
            $sections .= '<tr><td><strong>'.$t($section['title_key']).'</strong></td><td class="'.e($section['status']).'">'.$t('lpApi.status.'.$section['status']).'</td><td>'.$t($section['summary_key'], $section['params']).'</td></tr>';
        }

        $alerts = '';

        foreach ($overview['alerts'] as $alert) {
            $alerts .= '<tr><td>'.$t('lpApi.severity.'.$alert['severity']).'</td><td>'.$t($alert['title_key']).'</td><td>'.$t($alert['message_key'], $alert['params']).'</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;border-collapse:collapse;margin-bottom:12px}th,td{border-bottom:1px solid #ccc;padding:3px 4px;text-align:left}.n{text-align:right}h1{font-size:15px}h2{font-size:11px;margin:10px 0 4px}.critical{color:#b00020;font-weight:bold}.watch{color:#b26a00;font-weight:bold}.ok{color:#2e7d32}</style></head><body>'
            .'<h1>'.$t('lpApi.report.title').'</h1><p><strong>'.e((string) $report['company']['name']).'</strong><br>'.$t('lpApi.export.as_of').': '.e((string) $report['as_of']).' · '.$t('lpApi.export.currency').': '.e((string) $report['currency']).' · '.$t('lpApi.export.opening_cash').': '.$money($overview['opening_cash']).'</p>'
            .'<h2>'.$t('lpApi.report.horizons').'</h2><table><thead><tr><th>'.$t('lpApi.export.horizon').'</th><th class="n">'.$t('lpApi.export.inflows').'</th><th class="n">'.$t('lpApi.export.outflows').'</th><th class="n">'.$t('lpApi.export.net').'</th><th class="n">'.$t('lpApi.export.closing').'</th><th class="n">'.$t('lpApi.export.min_balance').'</th>'.($hasScenario ? '<th class="n">'.$t('lpApi.export.scenario_closing').'</th>' : '').'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<h2>'.$t('lpApi.report.indicators').'</h2><table><tbody>'.$indicators.'</tbody></table>'
            .'<h2>'.$t('lpApi.report.eba').'</h2><table><tbody>'.$sections.'</tbody></table>'
            .'<h2>'.$t('lpApi.report.alerts').'</h2><table><tbody>'.($alerts === '' ? '<tr><td>'.$t('lpApi.report.no_alerts').'</td></tr>' : $alerts).'</tbody></table>'
            .'</body></html>';
    }

    /**
     * The indicator rows of the summary: label, baseline and, when a scenario is active, scenario value.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>|null  $scenario
     * @param  callable(string, array<string, mixed>=): string  $t
     * @return list<list<string|float>>
     */
    private function indicatorRows(array $baseline, ?array $scenario, callable $t): array
    {
        $pick = static fn (array $indicators): array => [
            'lpApi.export.dscr' => $indicators['dscr']['value'] ?? '-',
            'lpApi.export.operating_cash_flow' => $indicators['dscr']['operating_cash_flow'],
            'lpApi.export.debt_service' => $indicators['dscr']['debt_service']['total'],
            'lpApi.export.net_equity' => $indicators['capital']['net_equity'],
            'lpApi.export.pfn' => $indicators['pfn']['pfn'],
            'lpApi.export.ebitda' => $indicators['pfn']['ebitda'],
            'lpApi.export.pfn_ebitda' => $indicators['pfn']['pfn_ebitda'] ?? '-',
        ];
        $base = $pick($baseline);
        $scn = $scenario === null ? null : $pick($scenario);
        $rows = [];

        foreach ($base as $key => $value) {
            $rows[] = [$t($key), $value, ...($scn === null ? [] : [$scn[$key]])];
        }

        return $rows;
    }
}
