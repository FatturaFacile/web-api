<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Exceptions\SatisfactionException;
use Modules\CustomerSatisfaction\Models\Iso9001SatisfactionReport;

/**
 * Management Review (Riesame della Direzione) reports for UNI EN ISO 9001:2015 § 9.1.2: freezes the live analytics of a period into a
 * snapshot (KPIs, breakdown by the chosen dimension, monthly trend, sentiment, closed-loop SLA and corrective actions) and renders it
 * as JSON, CSV or a printable HTML document.
 */
final class Iso9001ReportService
{
    public const string Standard = 'UNI EN ISO 9001:2015 § 9.1.2';

    /** @var list<string> */
    public const array Formats = ['json', 'csv', 'html'];

    public function __construct(
        private readonly SatisfactionAnalyticsService $analytics,
        private readonly AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $input  title, group_by, filters (from, to, survey_type, department_code, ...), management_review_notes
     */
    public function generate(array $input, ?string $userId = null): Iso9001SatisfactionReport
    {
        $filters = (array) ($input['filters'] ?? []);
        $period = $this->analytics->period($filters);
        $groupBy = ReportGroupBy::from((string) ($input['group_by'] ?? ReportGroupBy::Overall->value));
        $filters['from'] = $period['from']->toDateString();
        $filters['to'] = $period['to']->toDateString();

        $overview = $this->analytics->overview($filters);

        $report = Iso9001SatisfactionReport::query()->create([
            'title' => (string) ($input['title'] ?? 'ISO 9001 § 9.1.2 '.$filters['from'].' – '.$filters['to']),
            'period_from' => $filters['from'],
            'period_to' => $filters['to'],
            'group_by' => $groupBy->value,
            'filters' => $filters,
            'metrics_snapshot' => [
                'standard' => self::Standard,
                'generated_at' => now()->toIso8601String(),
                'overview' => $overview,
                'breakdown' => $this->analytics->breakdown($groupBy, $filters),
                'trend' => $this->analytics->trend('month', $filters),
                'sentiment' => $this->analytics->sentiment($filters),
                'root_causes' => $this->analytics->rootCauses($period),
            ],
            'management_review_notes' => $input['management_review_notes'] ?? null,
            'generated_by_user_id' => $userId,
            'created_by' => $userId,
        ]);

        $this->auditLedger->record('ISO9001_SATISFACTION_REPORT_GENERATED', $report, null, ['group_by' => $groupBy->value, 'period' => [$filters['from'], $filters['to']]], 'MODULE_245_CSAT');

        return $report;
    }

    /**
     * @return array{content: string, mime: string, filename: string}
     */
    public function export(Iso9001SatisfactionReport $report, string $format): array
    {
        if (! in_array($format, self::Formats, true)) {
            throw new SatisfactionException('unsupported_export_format', 422);
        }

        $content = match ($format) {
            'json' => (string) json_encode($this->payload($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'csv' => $this->csv($report),
            default => $this->html($report),
        };

        $report->forceFill(['exported_format' => $format])->save();

        return [
            'content' => $content,
            'mime' => match ($format) {
                'json' => 'application/json',
                'csv' => 'text/csv; charset=UTF-8',
                default => 'text/html; charset=UTF-8',
            },
            'filename' => Str::slug($report->title).'.'.$format,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Iso9001SatisfactionReport $report): array
    {
        return [
            'id' => $report->id,
            'title' => $report->title,
            'standard' => self::Standard,
            'period' => ['from' => $report->period_from->toDateString(), 'to' => $report->period_to->toDateString()],
            'group_by' => $report->group_by->value,
            'filters' => $report->filters,
            'management_review_notes' => $report->management_review_notes,
            'metrics' => $report->metrics_snapshot,
        ];
    }

    private function csv(Iso9001SatisfactionReport $report): string
    {
        $metrics = $report->metrics_snapshot;
        $handle = fopen('php://temp', 'r+');
        $columns = ['dimension', 'responses', 'nps', 'promoters_pct', 'passives_pct', 'detractors_pct', 'csat_pct', 'csat_average', 'ces_average', 'ces_low_effort_rate'];

        fputcsv($handle, [__('customer_satisfaction.report.title'), $report->title, self::Standard], ',', '"', '\\');
        fputcsv($handle, [__('customer_satisfaction.report.period'), $report->period_from->toDateString(), $report->period_to->toDateString()], ',', '"', '\\');
        fputcsv($handle, array_map(static fn (string $column): string => __('customer_satisfaction.report.columns.'.$column), $columns), ',', '"', '\\');

        foreach ((array) ($metrics['breakdown'] ?? []) as $row) {
            fputcsv($handle, $this->csvRow((string) ($row['label'] ?? $row['key'] ?? '—'), $row), ',', '"', '\\');
        }

        fputcsv($handle, [], ',', '"', '\\');
        fputcsv($handle, array_map(static fn (string $column): string => __('customer_satisfaction.report.columns.'.($column === 'dimension' ? 'month' : $column)), $columns), ',', '"', '\\');

        foreach ((array) ($metrics['trend'] ?? []) as $row) {
            fputcsv($handle, $this->csvRow((string) $row['bucket'], $row), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int|float|null>
     */
    private function csvRow(string $label, array $row): array
    {
        return [
            $label, $row['total'] ?? 0, $row['nps']['score'] ?? null, $row['nps']['promoters_pct'] ?? null, $row['nps']['passives_pct'] ?? null,
            $row['nps']['detractors_pct'] ?? null, $row['csat']['positive_pct'] ?? null, $row['csat']['average'] ?? null,
            $row['ces']['average'] ?? null, $row['ces']['low_effort_rate'] ?? null,
        ];
    }

    private function html(Iso9001SatisfactionReport $report): string
    {
        $metrics = $report->metrics_snapshot;
        $kpis = $metrics['overview']['kpis'] ?? [];
        $loop = $metrics['overview']['closed_loop'] ?? [];
        $t = static fn (string $key): string => e(__('customer_satisfaction.report.'.$key));
        $v = static fn (mixed $value): string => $value === null ? '—' : e((string) $value);

        $rows = '';
        foreach ((array) ($metrics['breakdown'] ?? []) as $row) {
            $rows .= '<tr><td>'.$v($row['label'] ?? $row['key'] ?? null).'</td><td>'.$v($row['total'] ?? 0).'</td><td>'.$v($row['nps']['score'] ?? null).'</td><td>'.$v($row['csat']['positive_pct'] ?? null).'</td><td>'.$v($row['ces']['average'] ?? null).'</td></tr>';
        }

        $causes = '';
        foreach ((array) ($metrics['root_causes'] ?? []) as $cause) {
            $causes .= '<li>'.e((string) $cause['root_cause_code']).': '.e((string) $cause['count']).'</li>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($report->title).'</title>'
            .'<style>body{font-family:Arial,sans-serif;margin:2rem;color:#222}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{border:1px solid #bbb;padding:.4rem;text-align:left}h1,h2{margin-bottom:.3rem}@media print{body{margin:0}}</style></head><body>'
            .'<h1>'.e($report->title).'</h1><p>'.e(self::Standard).' · '.$t('period').': '.e($report->period_from->toDateString()).' – '.e($report->period_to->toDateString()).'</p>'
            .'<h2>'.$t('kpis').'</h2><table><tr><th>NPS</th><th>CSAT %</th><th>CES</th><th>'.$t('response_rate').'</th><th>'.$t('sla_compliance').'</th><th>'.$t('open_cases').'</th></tr>'
            .'<tr><td>'.$v($kpis['nps']['score'] ?? null).'</td><td>'.$v($kpis['csat']['positive_pct'] ?? null).'</td><td>'.$v($kpis['ces']['average'] ?? null).'</td><td>'.$v($metrics['overview']['response_rate']['rate'] ?? null).'</td><td>'.$v($loop['sla_compliance'] ?? null).'</td><td>'.$v($loop['open'] ?? null).'</td></tr></table>'
            .'<h2>'.$t('breakdown').' ('.e($report->group_by->value).')</h2><table><tr><th>'.$t('dimension').'</th><th>'.$t('responses').'</th><th>NPS</th><th>CSAT %</th><th>CES</th></tr>'.$rows.'</table>'
            .'<h2>'.$t('corrective_actions').'</h2><p>'.$t('resolution_rate').': '.$v($loop['resolution_rate'] ?? null).'</p><ul>'.$causes.'</ul>'
            .'<h2>'.$t('management_review_notes').'</h2><p>'.nl2br(e((string) $report->management_review_notes)).'</p></body></html>';
    }
}
