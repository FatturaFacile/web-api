<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Domain\Support\KpiCalculator;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyResponse;

/**
 * Live ISO 9001:2015 § 9.1.2 analytics over the answers of the active company: headline KPIs, closed-loop SLA adherence,
 * breakdowns by department, technician, product line and branch, time series and the sentiment of the open comments.
 * Aggregation runs in PHP over narrow rows so it is identical on SQLite and PostgreSQL.
 *
 * Filters: from, to (dates, default the last 90 days), survey_type, department_code, technician_id, product_line_code, branch_code.
 */
final class SatisfactionAnalyticsService
{
    /** @var list<string> */
    private const array Filterable = ['survey_type', 'department_code', 'technician_id', 'product_line_code', 'branch_code'];

    /** @var list<string> */
    private const array StopWords = [
        'that', 'this', 'with', 'have', 'from', 'were', 'they', 'there', 'been', 'would', 'about', 'which', 'their', 'than', 'very', 'also',
        'sono', 'della', 'delle', 'degli', 'questo', 'questa', 'stato', 'stata', 'molto', 'anche', 'come', 'sulla', 'nella', 'nelle',
        'nicht', 'eine', 'einen', 'einer', 'sehr', 'auch', 'wurde', 'haben', 'aber', 'oder', 'dass',
        'avec', 'pour', 'dans', 'nous', 'vous', 'sont', 'mais', 'cette', 'plus', 'tres', 'très',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: Carbon, to: Carbon}
     */
    public function period(array $filters): array
    {
        $to = isset($filters['to']) ? Carbon::parse((string) $filters['to'])->endOfDay() : now()->endOfDay();
        $from = isset($filters['from']) ? Carbon::parse((string) $filters['from'])->startOfDay() : $to->copy()->subDays(89)->startOfDay();

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters = []): array
    {
        $period = $this->period($filters);
        $rows = $this->rows($filters, ['survey_type', 'score']);

        return [
            'period' => ['from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString()],
            'kpis' => KpiCalculator::compute($rows),
            'response_rate' => $this->responseRate($period),
            'closed_loop' => $this->closedLoop($period),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function breakdown(ReportGroupBy $groupBy, array $filters = []): array
    {
        $column = $groupBy->column();

        if ($column === null) {
            return [['key' => 'overall', 'label' => 'overall', ...KpiCalculator::compute($this->rows($filters, ['survey_type', 'score']))]];
        }

        $groups = [];
        foreach ($this->rows($filters, ['survey_type', 'score', $column]) as $row) {
            $groups[(string) ($row[$column] ?? '')][] = $row;
        }

        $labels = $groupBy === ReportGroupBy::Technician ? $this->technicianNames(array_keys($groups)) : [];
        $result = [];

        foreach ($groups as $key => $rows) {
            $result[] = [
                'key' => $key === '' ? null : $key,
                'label' => $key === '' ? null : ($labels[$key] ?? $key),
                ...KpiCalculator::compute($rows),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function trend(string $granularity, array $filters = []): array
    {
        $buckets = [];

        foreach ($this->rows($filters, ['survey_type', 'score', 'initial_clicked_at']) as $row) {
            $buckets[$this->bucket(Carbon::parse((string) $row['initial_clicked_at']), $granularity)][] = $row;
        }

        ksort($buckets);

        return array_map(
            static fn (string $label, array $rows): array => ['bucket' => $label, ...KpiCalculator::compute($rows)],
            array_keys($buckets),
            $buckets,
        );
    }

    /**
     * Sentiment polarity and recurring themes of the comments and voice transcripts.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function sentiment(array $filters = []): array
    {
        $rows = $this->rows($filters, ['sentiment_label', 'sentiment_score', 'sentiment_topics', 'comment_text', 'voice_transcript'], fn (Builder $query) => $query->whereNotNull('sentiment_label'));

        $labels = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $topics = [];
        $words = [];
        $sum = 0.0;

        foreach ($rows as $row) {
            $labels[(string) $row['sentiment_label']] = ($labels[(string) $row['sentiment_label']] ?? 0) + 1;
            $sum += (float) $row['sentiment_score'];

            foreach ((array) json_decode((string) ($row['sentiment_topics'] ?? '[]'), true) as $topic) {
                $topics[(string) $topic] = ($topics[(string) $topic] ?? 0) + 1;
            }

            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower(trim(($row['comment_text'] ?? '').' '.($row['voice_transcript'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 4 && ! in_array($word, self::StopWords, true)) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
        }

        arsort($topics);
        arsort($words);
        $count = count($rows);

        return [
            'analyzed' => $count,
            'average_score' => $count === 0 ? null : round($sum / $count, 2),
            'labels' => $labels,
            'topics' => array_map(static fn (string $topic, int $total): array => ['topic' => $topic, 'count' => $total], array_keys($topics), $topics),
            'keywords' => array_map(static fn (string $word, int $total): array => ['keyword' => $word, 'count' => $total], array_slice(array_keys($words), 0, 15), array_slice($words, 0, 15)),
        ];
    }

    /**
     * Share of the surveys that reached a customer and were answered.
     *
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array{sent: int, responded: int, rate: float|null}
     */
    public function responseRate(array $period): array
    {
        $reached = SurveyDispatch::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->whereIn('status', [DispatchStatus::Sent->value, DispatchStatus::Delivered->value, DispatchStatus::Responded->value, DispatchStatus::Expired->value])
            ->count();
        $responded = SurveyDispatch::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->where('status', DispatchStatus::Responded->value)
            ->count();

        return ['sent' => $reached, 'responded' => $responded, 'rate' => $reached === 0 ? null : KpiCalculator::pct($responded, $reached)];
    }

    /**
     * Closed-loop figures: how many detractor cases, how many solved and how many were first contacted inside the SLA.
     *
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array<string, int|float|null>
     */
    public function closedLoop(array $period): array
    {
        $cases = DetractorRecoveryCase::query()->whereBetween('created_at', [$period['from'], $period['to']])->get();

        $total = $cases->count();
        $open = $cases->filter(fn (DetractorRecoveryCase $case): bool => ! $case->status->isFinal())->count();
        $solved = $total - $open;

        $evaluated = $cases->filter(fn (DetractorRecoveryCase $case): bool => $case->first_contacted_at !== null || $case->sla_due_at->isPast());
        $compliant = $evaluated->filter(fn (DetractorRecoveryCase $case): bool => $case->first_contacted_at !== null && $case->first_contacted_at->lessThanOrEqualTo($case->sla_due_at))->count();

        return [
            'total' => $total,
            'open' => $open,
            'resolved' => $solved,
            'resolution_rate' => $total === 0 ? null : KpiCalculator::pct($solved, $total),
            'sla_evaluated' => $evaluated->count(),
            'sla_compliant' => $compliant,
            'sla_compliance' => $evaluated->isEmpty() ? null : KpiCalculator::pct($compliant, $evaluated->count()),
            // Past the deadline and still not contacted: the SLA is breached right now.
            'open_overdue' => $cases->filter(fn (DetractorRecoveryCase $case): bool => ! $case->status->isFinal() && $case->first_contacted_at === null && $case->sla_due_at->isPast())->count(),
        ];
    }

    /**
     * Root causes of the solved cases: the corrective action summary of the Management Review.
     *
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return list<array{root_cause_code: string, count: int}>
     */
    public function rootCauses(array $period): array
    {
        return DetractorRecoveryCase::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->whereNotNull('root_cause_code')
            ->get()
            ->groupBy('root_cause_code')
            ->map(static fn ($group, $code): array => ['root_cause_code' => (string) $code, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters, array $columns, ?callable $extra = null): array
    {
        $period = $this->period($filters);
        $query = SurveyResponse::query()->whereBetween('initial_clicked_at', [$period['from'], $period['to']]);

        foreach (self::Filterable as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if ($extra !== null) {
            $extra($query);
        }

        return $query->toBase()->get($columns)->map(static fn (object $row): array => (array) $row)->all();
    }

    private function bucket(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->format('o-\WW'),
            'quarter' => $date->format('Y').'-Q'.$date->quarter,
            default => $date->format('Y-m'),
        };
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    private function technicianNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));

        if ($ids === [] || ! DB::getSchemaBuilder()->hasTable('esm_service_technicians')) {
            return [];
        }

        $names = [];
        foreach (DB::table('esm_service_technicians')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $technician) {
            $names[(string) $technician->id] = trim($technician->first_name.' '.$technician->last_name);
        }

        return $names;
    }
}
