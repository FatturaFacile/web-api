<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\ReportQueryRequest;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Services\ContributionMarginReportService;
use Modules\AnalyticalAccounting\Services\DirectVsApportionedReportService;

/**
 * Contribution margin income statement and the direct-vs-apportioned comparison, as JSON or CSV.
 */
final class ReportController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(
        private readonly ContributionMarginReportService $margin,
        private readonly DirectVsApportionedReportService $comparison,
    ) {}

    public function contributionMargin(ReportQueryRequest $request): JsonResponse|Response
    {
        $this->authorize('viewAny', AnalyticalEntry::class);
        $filters = $request->validated();
        $report = $this->domain(fn (): array => $this->margin->statement($this->tenantId(), $filters));
        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv(['line', 'amount', 'margin_pct'], $this->margin->toRows($report['statement']), 'contribution-margin.csv');
        }

        return response()->json(['success' => true, 'data' => $report]);
    }

    public function directVsApportioned(ReportQueryRequest $request): JsonResponse|Response
    {
        $this->authorize('viewAny', AnalyticalEntry::class);
        $filters = $request->validated();
        $report = $this->comparison->compare($this->tenantId(), $filters);
        if (($filters['format'] ?? 'json') === 'csv') {
            $rows = array_map(static fn (array $r): array => [$r['code'], $r['name'], $r['direct_cost'], $r['apportioned_in'], $r['apportioned_out'], $r['net_cost'], $r['variance'], $r['share_pct']], $report['rows']);

            return $this->csv(['code', 'name', 'direct_cost', 'apportioned_in', 'apportioned_out', 'net_cost', 'variance', 'share_pct'], $rows, 'direct-vs-apportioned.csv');
        }

        return response()->json(['success' => true, 'data' => $report]);
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<int, string>>  $rows
     */
    private function csv(array $header, array $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }
}
