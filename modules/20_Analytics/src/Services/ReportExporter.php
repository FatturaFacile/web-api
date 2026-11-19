<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Analytics\Export\PivotTable;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Pivot\PivotDefinition;
use Modules\Analytics\Pivot\PivotService;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Produces the export of a report (flat or pivot) as a stream: the report is validated and counted under the hard row and
 * time limits of the engine, an {@see ExportRun} is recorded and the audit entry written, and only then the file is
 * produced, in chunks, to the HTTP response or to a stored file. Every export is audit logged, whoever asked for it (a
 * user downloading, or the scheduler on behalf of the owner of a schedule), with the dataset, the full specification, the
 * format, the locale, the number of rows and whether the row cap cut the report. The entry is written before the first
 * byte so an interrupted download leaves a trace; the size, checksum, duration and status are added to the run when the
 * file is complete (the run is the execution log of the report).
 */
final readonly class ReportExporter
{
    private const string ModuleCode = 'MODULE_20_ANALYTICS';

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportEngine $engine,
        private ReportExportFormatter $formatter,
        private AuditLedgerInterface $auditLedger,
        private TenantContext $tenantContext,
        private PivotService $pivots,
    ) {}

    /**
     * Validates and counts the report, records the run and the audit entry. Nothing of the file is produced yet.
     *
     * @return array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}
     */
    public function prepare(ReportSpec $spec, User $user, string $format, ?DesignedReport $report = null, ?ExportSchedule $schedule = null, bool $store = false, ?string $locale = null): array
    {
        $opened = $this->engine->openExport($spec, $user);

        return $this->build($spec, $user, $format, $report, $schedule, $store, $locale, $opened['columns'], $opened['meta'], $opened['rows'], null, true);
    }

    /**
     * The same for a pivot: the matrix is calculated now (all its levels of totals), flattened into a table, and exported.
     *
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $calculatedFields
     * @return array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}
     */
    public function preparePivot(ReportSpec $spec, PivotDefinition $pivot, array $calculatedFields, User $user, string $format, ?DesignedReport $report = null, ?ExportSchedule $schedule = null, bool $store = false, ?string $locale = null): array
    {
        $result = $this->pivots->execute($spec, $pivot, $calculatedFields, $user);
        $table = PivotTable::flatten($result, $spec->dataset, new RegionalReportFormatter($locale));
        $rows = $table['rows'];
        $meta = ['dataset' => $spec->dataset, 'row_count' => count($rows), 'truncated' => (bool) $result['meta']['truncated'], 'row_limit' => $this->engine->maxRows(), 'period' => $this->engine->periods($spec)['current'], 'comparison_period' => null];

        return $this->build($spec, $user, $format, $report, $schedule, $store, $locale, $table['columns'], $meta, static fn (): array => $rows, $result['chart'], false);
    }

    /**
     * The HTTP response that streams a prepared export.
     *
     * @param  array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}  $prepared
     */
    public function response(array $prepared): StreamedResponse
    {
        return response()->stream(function () use ($prepared): void {
            ($prepared['write'])(static function (string $chunk): void {
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            });
        }, 200, [
            'Content-Type' => $prepared['mime'],
            'Content-Disposition' => 'attachment; filename="'.$prepared['file_name'].'"',
            'X-Report-Truncated' => $prepared['truncated'] ? 'true' : 'false',
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Writes a prepared export to the private disk (run prepared with `store: true`) and returns the path of a temporary
     * copy, which the caller must delete.
     *
     * @param  array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}  $prepared
     */
    public function store(array $prepared): string
    {
        $run = $prepared['run'];
        $temporary = tempnam(sys_get_temp_dir(), 'analytics');
        $handle = $temporary === false ? false : fopen($temporary, 'wb');

        if ($temporary === false || $handle === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        try {
            ($prepared['write'])(static function (string $chunk) use ($handle): void {
                fwrite($handle, $chunk);
            });
            fclose($handle);
            $source = fopen($temporary, 'rb');

            if ($source === false) {
                throw new RuntimeException('The export could not be read back.');
            }

            Storage::disk((string) $run->disk)->writeStream((string) $run->path, $source);

            if (is_resource($source)) {
                fclose($source);
            }
        } catch (Throwable $exception) {
            @unlink($temporary);

            throw $exception;
        }

        return $temporary;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, mixed>  $meta
     * @param  Closure(): iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $chart
     * @return array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}
     */
    private function build(ReportSpec $spec, User $user, string $format, ?DesignedReport $report, ?ExportSchedule $schedule, bool $store, ?string $locale, array $columns, array $meta, Closure $rows, ?array $chart, bool $totals): array
    {
        $regionalLocale = $locale === null ? null : RegionalReportFormatter::normalise($locale);
        $fileName = Str::slug($report === null ? $spec->dataset : $report->name).'-'.now()->format('Ymd-His').'.'.$this->formatter->extension($format);
        $tenantId = (string) $this->tenantContext->getTenantId();

        $run = new ExportRun([
            'user_id' => $user->id,
            'designed_report_id' => $report?->id,
            'schedule_id' => $schedule?->id,
            'dataset' => $spec->dataset,
            'format' => $format,
            'locale' => $regionalLocale,
            'status' => 'running',
            'row_count' => (int) $meta['row_count'],
            'truncated' => (bool) $meta['truncated'],
            'file_name' => $fileName,
        ]);

        if ($store) {
            $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
            $run->fill(['disk' => $disk, 'path' => "tenants/{$tenantId}/analytics-exports/".Str::uuid().'.'.$this->formatter->extension($format), 'expires_at' => now()->addDays(max(1, (int) config('analytics.reporting.link_ttl_days', 7)))]);
        }

        $run->save();

        $this->auditLedger->recordPayload([
            'action' => $schedule === null ? 'ANALYTICS_REPORT_EXPORTED' : 'ANALYTICS_SCHEDULED_EXPORT_GENERATED',
            'model_class' => ExportRun::class,
            'model_id' => (string) $run->id,
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'payload_before' => null,
            'payload_after' => [
                'dataset' => $spec->dataset,
                'spec' => $spec->toArray(),
                'format' => $format,
                'locale' => $regionalLocale,
                'row_count' => $run->row_count,
                'truncated' => $run->truncated,
                'designed_report_id' => $report?->id,
                'schedule_id' => $schedule?->id,
                'stored' => $store,
                'period' => $meta['period'],
            ],
            'module_code' => self::ModuleCode,
            'actor_user_id' => (string) $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $tenant = $this->tenantContext->getTenant();
        $context = [
            'sheet_name' => $report === null ? $spec->dataset : $report->name,
            'dataset' => $spec->dataset,
            'report' => $report?->name,
            'title' => $report->name ?? $spec->dataset,
            'spec' => $spec->toArray(),
            'generated_at' => now(),
            'totals' => $totals,
            'chart' => $chart,
            'filter_summary' => $this->filterSummary($spec),
        ];

        if ($regionalLocale !== null) {
            $branding = $tenant?->branding() ?? [];
            $context += ['locale' => $regionalLocale, 'tenant_name' => (string) ($tenant->name ?? ''), 'tenant_logo' => $branding['brand_logo_url'] ?? null];
        }

        $write = function (callable $sink) use ($columns, $meta, $rows, $context, $format, $run): void {
            $hash = hash_init('sha256');
            $size = 0;
            $started = hrtime(true);

            try {
                $this->formatter->stream($columns, $rows(), $meta, $context, $format, function (string $chunk) use ($hash, &$size, $sink): void {
                    hash_update($hash, $chunk);
                    $size += strlen($chunk);
                    $sink($chunk);
                });
            } catch (Throwable $exception) {
                $run->update(['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 500), 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);

                throw $exception;
            }

            $run->update(['sha256' => hash_final($hash), 'size_bytes' => $size, 'status' => 'completed', 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
        };

        return ['run' => $run, 'file_name' => $fileName, 'mime' => $this->formatter->mimeType($format), 'truncated' => $run->truncated, 'write' => $write];
    }

    /**
     * The filters of the report as short readable lines, for the footer of a PDF.
     *
     * @return list<string>
     */
    private function filterSummary(ReportSpec $spec): array
    {
        $line = static fn (array $filter): string => $filter['field'].' '.$filter['operator'].(is_array($filter['value']) ? ' '.implode(', ', $filter['value']) : ($filter['value'] === null ? '' : ' '.$filter['value']));
        $summary = array_map($line, $spec->filters);

        if ($spec->filterTree !== null) {
            $walk = static function (array $node) use (&$walk, $line): string {
                $parts = array_map(static fn (array $child): string => isset($child['conditions']) ? '('.$walk($child).')' : $line($child), $node['conditions']);

                return implode(' '.strtoupper((string) $node['logic']).' ', $parts);
            };
            $summary[] = $walk($spec->filterTree);
        }

        if ($spec->period !== null) {
            $summary[] = 'period '.(isset($spec->period['preset']) ? $spec->period['preset'] : $spec->period['from'].' - '.$spec->period['to']);
        }

        return $summary;
    }
}
