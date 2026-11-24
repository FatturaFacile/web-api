<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\DataExport\Jobs\RunExportJob;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Models\ExportSchedule;
use Throwable;

/**
 * Queues, runs and links the exports of the history: the file is written to the private disk by a queued job, the
 * download goes through a signed, expiring link.
 */
final readonly class ExportJobService
{
    public const int LinkMinutes = 60;

    public const int RetentionDays = 7;

    private const int PageSize = 500;

    public function __construct(
        private ReportingDatasets $datasets,
        private ExcelCsvWriter $csv,
        private AccountantArchiveBuilder $archive,
        private TenantClock $clock,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Records the export and queues its run.
     */
    public function queue(string $dataset, ?string $from, ?string $to, ?string $userId, ?string $scheduleId = null): ExportJob
    {
        $job = ExportJob::create([
            'user_id' => $userId,
            'schedule_id' => $scheduleId,
            'dataset' => $dataset,
            'period_from' => $from,
            'period_to' => $to,
            'status' => ExportJob::Queued,
        ]);

        RunExportJob::dispatch((string) $job->id, (string) $job->tenant_id);

        return $job;
    }

    /**
     * Builds the file of a job and records the outcome; a failure is stored on the job, never thrown.
     */
    public function run(ExportJob $job): void
    {
        $job->update(['status' => ExportJob::Running, 'started_at' => now(), 'error' => null]);

        try {
            $result = $job->dataset === 'accountant_archive' ? $this->buildArchive($job) : $this->buildCsv($job);

            $job->update($result + [
                'status' => ExportJob::Completed,
                'completed_at' => now(),
                'expires_at' => now()->addDays(self::RetentionDays),
            ]);
        } catch (Throwable $exception) {
            $job->update(['status' => ExportJob::Failed, 'completed_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }

    /**
     * The signed link of a downloadable export.
     */
    public function downloadUrl(ExportJob $job): string
    {
        return URL::temporarySignedRoute('exports.download', now()->addMinutes(self::LinkMinutes), ['job' => $job->id]);
    }

    /**
     * Retries a failed export: the same dataset and period, queued again.
     */
    public function retry(ExportJob $job): ExportJob
    {
        $job->update(['status' => ExportJob::Queued, 'error' => null, 'completed_at' => null]);

        RunExportJob::dispatch((string) $job->id, (string) $job->tenant_id);

        return $job;
    }

    /**
     * Queues the exports of the schedules due today and moves each schedule to its next day.
     *
     * @return int number of exports queued
     */
    public function runDueSchedules(): int
    {
        $today = $this->clock->today();
        $queued = 0;

        foreach (ExportSchedule::query()->where('is_active', true)->whereDate('next_run_on', '<=', $today->toDateString())->get() as $schedule) {
            [$from, $to] = $this->periodOf((string) $schedule->period, $today);
            $this->queue((string) $schedule->dataset, $from, $to, (string) $schedule->user_id, (string) $schedule->id);
            $schedule->update(['last_run_at' => now(), 'next_run_on' => $this->nextRun((string) $schedule->frequency, $today)->toDateString()]);
            $queued++;
        }

        return $queued;
    }

    /**
     * Removes the stored files whose link window is over; the history row stays.
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        foreach (ExportJob::query()->whereNotNull('path')->where('expires_at', '<', now())->get() as $job) {
            Storage::disk((string) $job->disk)->delete((string) $job->path);
            $job->update(['path' => null]);
            $purged++;
        }

        return $purged;
    }

    /**
     * Runs a callback with the tenant of a job as the active one.
     */
    public function withTenant(string $tenantId, callable $callback): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant !== null) {
            $this->tenantContext->runWithTenant($tenant, $callback);
        }
    }

    /**
     * The first run of a new schedule: tomorrow.
     */
    public function firstRun(): CarbonImmutable
    {
        return $this->clock->today()->addDay();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public function periodOf(string $period, CarbonImmutable $today): array
    {
        return match ($period) {
            'previous_month' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'year_to_date' => [$today->startOfYear()->toDateString(), $today->toDateString()],
            default => [null, null],
        };
    }

    private function nextRun(string $frequency, CarbonImmutable $today): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $today->addWeek(),
            'monthly' => $today->addMonthNoOverflow(),
            default => $today->addDay(),
        };
    }

    /**
     * @return array{disk: string, path: string, file_name: string, mime_type: string, size_bytes: int, row_count: int}
     */
    private function buildCsv(ExportJob $job): array
    {
        $dataset = (string) $job->dataset;
        $period = ['from' => $job->period_from?->toDateString(), 'to' => $job->period_to?->toDateString()];
        $path = "exports/{$job->tenant_id}/{$job->id}.csv";
        $temp = tmpfile();
        $rows = 0;

        fwrite($temp, $this->csv->write($this->datasets->columns($dataset), []));

        foreach ($this->datasets->query($dataset, $period)->lazyById(self::PageSize, 'id')->chunk(self::PageSize) as $page) {
            fwrite($temp, $this->csv->rows($page->map(fn (object $record): array => array_values($this->datasets->row($dataset, $record)))));
            $rows += $page->count();
        }

        $size = (int) fstat($temp)['size'];
        rewind($temp);
        Storage::disk('local')->put($path, $temp);
        fclose($temp);

        return ['disk' => 'local', 'path' => $path, 'file_name' => "{$dataset}.csv", 'mime_type' => 'text/csv', 'size_bytes' => $size, 'row_count' => $rows];
    }

    /**
     * @return array{disk: string, path: string, file_name: string, mime_type: string, size_bytes: int, row_count: int}
     */
    private function buildArchive(ExportJob $job): array
    {
        $from = CarbonImmutable::parse($job->period_from);
        $to = CarbonImmutable::parse($job->period_to);
        $zip = $this->archive->build($from, $to);
        $path = "exports/{$job->tenant_id}/{$job->id}.zip";
        $size = (int) filesize($zip);
        $stream = fopen($zip, 'rb');
        Storage::disk('local')->put($path, $stream);
        fclose($stream);
        unlink($zip);

        return ['disk' => 'local', 'path' => $path, 'file_name' => "documents-{$from->toDateString()}-{$to->toDateString()}.zip", 'mime_type' => 'application/zip', 'size_bytes' => $size, 'row_count' => 0];
    }
}
