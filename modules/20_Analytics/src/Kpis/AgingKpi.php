<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ConvertedAmountSql;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Open items by days past due, as of today. Only posted documents with something left to settle count. The stored paid
 * amount is the one of today, so the aging of a past date cannot be rebuilt: the indicator is a snapshot and ignores
 * the dates of the report.
 */
abstract readonly class AgingKpi implements KpiCalculator
{
    /**
     * Bucket keys in order, from not yet due to the oldest.
     *
     * @var list<string>
     */
    public const array Buckets = ['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    /**
     * Inject the collaborators.
     */
    public function __construct(protected ConvertedAmountSql $sql, protected TenantClock $clock) {}

    /**
     * Base query of the open, posted documents of the active company, joined with the counterparty `contacts` row.
     */
    abstract protected function openItems(): Builder;

    /**
     * Table of the documents, to qualify columns and exchange rates.
     */
    abstract protected function table(): string;

    /**
     * SQL of the amount still to settle in the document currency.
     */
    abstract protected function outstandingSql(): string;

    /**
     * SQL of the due date, falling back to the document date.
     */
    abstract protected function dueSql(): string;

    /**
     * Column of the counterparty id on the documents.
     */
    abstract protected function counterpartyColumn(): string;

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'currency';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'snapshot';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $today = $this->clock->today();
        $amount = $this->amount();
        $convertible = $this->sql->convertible($this->table());
        $query = $this->openItems();

        foreach ($this->bucketSql($today) as $bucket => $condition) {
            $query->selectRaw("COALESCE(SUM({$amount}) FILTER (WHERE {$condition} AND {$convertible}), 0) AS {$bucket}");
        }

        $totals = $query
            ->selectRaw("COUNT(*) FILTER (WHERE {$convertible}) AS documents")
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$convertible}) AS excluded")
            ->first();

        $documents = (int) $totals->documents;
        $buckets = [];
        $sum = '0';

        foreach (self::Buckets as $bucket) {
            $buckets[$bucket] = bcadd((string) $totals->{$bucket}, '0', 2);
            $sum = bcadd($sum, $buckets[$bucket], 2);
        }

        return [
            'value' => $documents === 0 ? null : $sum,
            'details' => [
                'as_of' => $today->toDateString(),
                'currency' => $this->sql->currency(),
                'document_count' => $documents,
                'buckets' => $documents === 0 ? null : $buckets,
                'overdue' => $documents === 0 ? null : bcsub($sum, $buckets['not_due'], 2),
                'excluded_without_exchange_rate' => (int) $totals->excluded,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        $today = $this->clock->today();
        $amount = $this->amount();
        $name = "COALESCE(NULLIF(contacts.company_name, ''), TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')))";
        $counterparty = $this->table().'.'.$this->counterpartyColumn();
        $query = $this->openItems()
            ->whereRaw($this->sql->convertible($this->table()))
            ->groupBy($counterparty)
            ->groupByRaw($name)
            ->orderByRaw("SUM({$amount}) DESC")
            ->limit($limit)
            ->selectRaw("{$counterparty} AS counterparty_id, {$name} AS counterparty");

        foreach ($this->bucketSql($today) as $bucket => $condition) {
            $query->selectRaw("COALESCE(SUM({$amount}) FILTER (WHERE {$condition}), 0) AS {$bucket}");
        }

        return $query->selectRaw("SUM({$amount}) AS outstanding")
            ->get()
            ->map(static function (object $row): array {
                $result = ['counterparty_id' => (string) $row->counterparty_id, 'counterparty' => (string) $row->counterparty];

                foreach (self::Buckets as $bucket) {
                    $result[$bucket] = bcadd((string) $row->{$bucket}, '0', 2);
                }

                return [...$result, 'outstanding' => bcadd((string) $row->outstanding, '0', 2)];
            })
            ->all();
    }

    /**
     * Open amount of a row in the accounting currency.
     */
    private function amount(): string
    {
        return "({$this->outstandingSql()} * {$this->sql->rate($this->table())})";
    }

    /**
     * @return array<string, string>
     */
    private function bucketSql(CarbonImmutable $today): array
    {
        $day = static fn (int $offset): string => DB::getPdo()->quote($today->subDays($offset)->toDateString());
        $due = $this->dueSql();

        return [
            'not_due' => "{$due} >= {$day(0)}",
            'days_1_30' => "{$due} < {$day(0)} AND {$due} >= {$day(30)}",
            'days_31_60' => "{$due} < {$day(30)} AND {$due} >= {$day(60)}",
            'days_61_90' => "{$due} < {$day(60)} AND {$due} >= {$day(90)}",
            'over_90' => "{$due} < {$day(90)}",
        ];
    }
}
