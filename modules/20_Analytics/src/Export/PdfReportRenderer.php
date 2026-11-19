<?php

declare(strict_types=1);

namespace Modules\Analytics\Export;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a report or a pivot as an executive PDF: tenant name or logo, title, summary KPIs (totals of the additive
 * measures), the chart snapshot when the report has one, the table and a footer with the filter summary and the
 * generation time. Every value is escaped; the logo is embedded only when it is an inline image, so the renderer never
 * fetches a remote resource.
 */
final class PdfReportRenderer
{
    public function __construct(private readonly SvgChartRenderer $charts = new SvgChartRenderer) {}

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context  title, tenant_name, tenant_logo, chart, filter_summary, generated_at, dataset
     * @param  array<string, mixed>  $meta
     */
    public function render(array $columns, array $rows, array $meta, array $context, RegionalReportFormatter $regional): string
    {
        $dataset = (string) ($context['dataset'] ?? '');
        $header = '';
        $totals = [];

        foreach ($columns as $column) {
            if (($column['kind'] ?? '') === 'measure' && ! empty($column['additive'])) {
                $sum = '0';

                foreach ($rows as $row) {
                    if (is_numeric($row[$column['key']] ?? null) && ! ($row['__type'] ?? null)) {
                        $sum = bcadd($sum, (string) $row[$column['key']], 6);
                    }
                }

                $totals[(string) $column['key']] = $sum;
            }

            $header .= '<th>'.e($regional->header($dataset, $column)).'</th>';
        }

        $kpis = '';

        $shown = 0;

        foreach ($columns as $column) {
            if ($shown < 4 && isset($totals[(string) $column['key']])) {
                $kpis .= '<div class="kpi"><span>'.e($regional->header($dataset, $column)).'</span><strong>'.e($regional->cell($column, $totals[(string) $column['key']])).'</strong></div>';
                $shown++;
            }
        }

        $body = '';

        foreach ($rows as $row) {
            $class = isset($row['__type']) && $row['__type'] !== 'data' ? ' class="total"' : '';
            $body .= '<tr'.$class.'>';

            foreach ($columns as $column) {
                $numeric = ($column['kind'] ?? 'measure') !== 'dimension' || ($column['type'] ?? '') === 'number';
                $body .= '<td'.($numeric ? ' class="num"' : '').'>'.e($regional->cell($column, $row[$column['key']] ?? null)).'</td>';
            }

            $body .= '</tr>';
        }

        $logo = is_string($context['tenant_logo'] ?? null) && str_starts_with((string) $context['tenant_logo'], 'data:image/') ? '<img class="logo" src="'.e((string) $context['tenant_logo']).'">' : '';
        $chart = '';

        if (is_array($context['chart'] ?? null)) {
            $svg = $this->charts->render($context['chart']);
            $chart = $svg === '' ? '' : '<div class="chart"><img src="data:image/svg+xml;base64,'.base64_encode($svg).'"></div>';
        }

        $filters = (array) ($context['filter_summary'] ?? []);
        $footer = e((string) trans('analytics.export.filters', [], $regional->language())).': '.e($filters === [] ? '-' : implode('; ', $filters))
            .' · '.e((string) trans('analytics.export.generated_at', [], $regional->language())).' '.e($regional->dateTime($context['generated_at']))
            .(! empty($meta['truncated']) ? ' · '.e((string) trans('analytics.export.truncated', [], $regional->language())) : '');

        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#1f2937}h1{font-size:16px;margin:4px 0}.tenant{font-size:11px;color:#555}.logo{max-height:40px}'
            .'.kpis{margin:8px 0}.kpi{display:inline-block;margin-right:14px;padding:6px 10px;border:1px solid #d1d5db}.kpi span{display:block;color:#6b7280}.kpi strong{font-size:13px}'
            .'table{width:100%;border-collapse:collapse;margin-top:8px}th{background:#e5e7eb;text-align:left;padding:4px;border:1px solid #d1d5db}td{padding:3px 4px;border:1px solid #e5e7eb}.num{text-align:right}tr.total td{font-weight:bold;background:#f3f4f6}'
            .'.chart{margin:8px 0}.chart img{width:100%}.footer{position:fixed;bottom:-10px;left:0;right:0;font-size:8px;color:#6b7280}'
            .'</style></head><body>'
            .$logo.'<div class="tenant">'.e((string) ($context['tenant_name'] ?? '')).'</div><h1>'.e((string) ($context['title'] ?? '')).'</h1>'
            .'<div class="kpis">'.$kpis.'</div>'.$chart
            .'<table><thead><tr>'.$header.'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<div class="footer">'.$footer.'</div></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait')->output();
    }
}
