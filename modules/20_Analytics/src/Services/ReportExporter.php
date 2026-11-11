<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;

/**
 * Produces the export of a report: runs it with the hard row and time limits of the engine, formats it, records an
 * {@see ExportRun} and writes the audit entry. Every export is audit logged, whoever asked for it (a user downloading,
 * or the scheduler on behalf of the owner of a schedule), with the dataset, the full specification, the format, the
 * number of rows and whether the row cap cut the report.
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
     * Exports a report. With `$store` the file is also kept on the private disk until its link expires.
     *
     * @return array{content: string, file_name: string, mime: string, run: ExportRun}
     */
    public function export(ReportSpec $spec, User $user, string $format, ?DesignedReport $report = null, ?ExportSchedule $schedule = null, bool $store = false): array
    {
        $result = $this->engine->export($spec, $user);
        $content = $this->formatter->format($result, $format, ['sheet_name' => $report === null ? $spec->dataset : $report->name, 'dataset' => $spec->dataset, 'report' => $report?->name, 'spec' => $spec->toArray(), 'generated_at' => now()]);
        $fileName = Str::slug($report === null ? $spec->dataset : $report->name).'-'.now()->format('Ymd-His').'.'.$this->formatter->extension($format);
        $tenantId = (string) $this->tenantContext->getTenantId();

        $run = new ExportRun([
            'user_id' => $user->id,
            'designed_report_id' => $report?->id,
            'schedule_id' => $schedule?->id,
            'dataset' => $spec->dataset,
            'format' => $format,
            'row_count' => count($result['rows']),
            'truncated' => (bool) $result['meta']['truncated'],
            'file_name' => $fileName,
            'sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
        ]);

        if ($store) {
            $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
            $path = "tenants/{$tenantId}/analytics-exports/".Str::uuid().'.'.$this->formatter->extension($format);
            Storage::disk($disk)->put($path, $content);
            $run->fill(['disk' => $disk, 'path' => $path, 'expires_at' => now()->addDays(max(1, (int) config('analytics.reporting.link_ttl_days', 7)))]);
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
                'sha256' => $run->sha256,
                'period' => $result['meta']['period'],
            ],
            'module_code' => self::ModuleCode,
            'actor_user_id' => (string) $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return ['content' => $content, 'file_name' => $fileName, 'mime' => $this->formatter->mimeType($format), 'run' => $run];
    }
}
