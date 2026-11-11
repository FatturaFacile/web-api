<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Produces the export of a report as a stream: the report is validated and counted under the hard row and time limits
 * of the engine, an {@see ExportRun} is recorded and the audit entry written, and only then the file is produced, in
 * chunks, to the HTTP response or to a stored file. Every export is audit logged, whoever asked for it (a user
 * downloading, or the scheduler on behalf of the owner of a schedule), with the dataset, the full specification, the
 * format, the number of rows and whether the row cap cut the report. The entry is written before the first byte so an
 * interrupted download leaves a trace; the size and checksum are added to the run when the file is complete.
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
    ) {}

    /**
     * Validates and counts the report, records the run and the audit entry. Nothing of the file is produced yet.
     *
     * @return array{run: ExportRun, file_name: string, mime: string, truncated: bool, write: Closure(callable(string): void): void}
     */
    public function prepare(ReportSpec $spec, User $user, string $format, ?DesignedReport $report = null, ?ExportSchedule $schedule = null, bool $store = false): array
    {
        $opened = $this->engine->openExport($spec, $user);
        $meta = $opened['meta'];
        $fileName = Str::slug($report === null ? $spec->dataset : $report->name).'-'.now()->format('Ymd-His').'.'.$this->formatter->extension($format);
        $tenantId = (string) $this->tenantContext->getTenantId();

        $run = new ExportRun([
            'user_id' => $user->id,
            'designed_report_id' => $report?->id,
            'schedule_id' => $schedule?->id,
            'dataset' => $spec->dataset,
            'format' => $format,
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

        $context = ['sheet_name' => $report === null ? $spec->dataset : $report->name, 'dataset' => $spec->dataset, 'report' => $report?->name, 'spec' => $spec->toArray(), 'generated_at' => now()];
        $write = function (callable $sink) use ($opened, $meta, $context, $format, $run): void {
            $hash = hash_init('sha256');
            $size = 0;

            $this->formatter->stream($opened['columns'], ($opened['rows'])(), $meta, $context, $format, function (string $chunk) use ($hash, &$size, $sink): void {
                hash_update($hash, $chunk);
                $size += strlen($chunk);
                $sink($chunk);
            });

            $run->update(['sha256' => hash_final($hash), 'size_bytes' => $size]);
        };

        return ['run' => $run, 'file_name' => $fileName, 'mime' => $this->formatter->mimeType($format), 'truncated' => $run->truncated, 'write' => $write];
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
}
