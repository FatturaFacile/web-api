<?php

declare(strict_types=1);

namespace Modules\Analytics\Contracts;

use Modules\Analytics\Support\ReportPeriod;

/**
 * One indicator of the reporting layer. A calculator reads existing posted records only and returns null for the value
 * when there is nothing to report: a missing figure is never replaced by zero.
 */
interface KpiCalculator
{
    /**
     * Stable key used in the API, in saved report definitions and in exports.
     */
    public function key(): string;

    /**
     * Human readable name of the indicator.
     */
    public function label(): string;

    /**
     * Unit of the value: `currency`, `count` or `percent`.
     */
    public function unit(): string;

    /**
     * `period` indicators cover the dates of the report and can be compared; `snapshot` indicators describe the
     * situation of today (open items, stock, projects) which cannot be rebuilt for a past date from the stored data.
     */
    public function kind(): string;

    /**
     * Module key of `config/permissions.php` whose read access the caller needs to see this indicator.
     */
    public function requiredModule(): string;

    /**
     * @return array{value: ?string, details: array<string, mixed>}
     */
    public function calculate(ReportPeriod $period): array;

    /**
     * Rows of the detail table (by customer, supplier, project...) for exports, already limited to $limit.
     *
     * @return list<array<string, scalar|null>>
     */
    public function rows(ReportPeriod $period, int $limit): array;
}
