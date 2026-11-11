<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Analytics\Mail\ScheduledReportMail;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Documents\Services\DocumentLibrary;
use RuntimeException;
use Throwable;

/**
 * Runs the scheduled exports of the active company. A schedule runs with the permissions of its owner at the moment of
 * the run: a user who lost the dataset (or the company) gets a failed run, never the data. Each run is idempotent per
 * day (the next date moves past today whatever the outcome), so running the command twice does not mail twice, and a
 * failure is recorded on the schedule instead of being retried every hour.
 *
 * Delivery: an e-mail with a signed link to the stored file, a document in the Documents module, or both.
 */
final readonly class ScheduledExportService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportEngine $engine,
        private ReportExporter $exporter,
        private ReportExportFormatter $formatter,
        private TenantClock $clock,
        private TenantContext $tenantContext,
        private ModuleAccessGate $gate,
        private DocumentLibrary $documents,
    ) {}

    /**
     * First run of a schedule that starts being active today: tomorrow or the first matching day after.
     */
    public function firstRunOn(string $frequency, ?int $dayOfWeek, ?int $dayOfMonth): CarbonImmutable
    {
        return $this->onOrAfter($this->clock->today()->addDay(), $frequency, $dayOfWeek, $dayOfMonth);
    }

    /**
     * First day on or after the given one that matches the frequency. Months use days 1 to 28 so every month has one.
     */
    public function onOrAfter(CarbonImmutable $from, string $frequency, ?int $dayOfWeek, ?int $dayOfMonth): CarbonImmutable
    {
        $from = $from->startOfDay();

        return match ($frequency) {
            'weekly' => $from->addDays(((int) ($dayOfWeek ?? 1) - $from->dayOfWeekIso + 7) % 7),
            'monthly' => $from->day <= (int) ($dayOfMonth ?? 1)
                ? $from->setDay((int) ($dayOfMonth ?? 1))
                : $from->addMonthNoOverflow()->setDay((int) ($dayOfMonth ?? 1)),
            default => $from,
        };
    }

    /**
     * Runs every active schedule of the company that is due.
     *
     * @return array{succeeded: int, failed: int}
     */
    public function runDue(): array
    {
        $today = $this->clock->today();
        $outcome = ['succeeded' => 0, 'failed' => 0];
        $due = ExportSchedule::query()->where('is_active', true)->whereDate('next_run_on', '<=', $today->toDateString())->get();

        foreach ($due as $schedule) {
            $this->runSchedule($schedule, $today) ? $outcome['succeeded']++ : $outcome['failed']++;
        }

        return $outcome;
    }

    /**
     * Runs one schedule now and records the outcome on it.
     */
    public function runSchedule(ExportSchedule $schedule, ?CarbonImmutable $today = null): bool
    {
        $today ??= $this->clock->today();
        $error = null;

        try {
            $this->deliver($schedule);
        } catch (Throwable $exception) {
            report($exception);
            $error = mb_substr($exception->getMessage(), 0, 500);
        }

        $schedule->update([
            'last_run_at' => now(),
            'last_status' => $error === null ? 'ok' : 'failed',
            'last_error' => $error,
            'next_run_on' => $this->onOrAfter($today->addDay(), $schedule->frequency, $schedule->day_of_week, $schedule->day_of_month)->toDateString(),
        ]);

        return $error === null;
    }

    /**
     * Removes the stored files whose link has expired. The run rows stay as the record that the export happened.
     */
    public function purgeExpired(): int
    {
        $count = 0;

        foreach (ExportRun::query()->whereNotNull('path')->where('expires_at', '<', now())->get() as $run) {
            Storage::disk((string) $run->disk)->delete((string) $run->path);
            $run->update(['path' => null]);
            $count++;
        }

        return $count;
    }

    /**
     * @throws RuntimeException when the report is gone, the owner left or cannot read the dataset any more, or a delivery fails
     */
    private function deliver(ExportSchedule $schedule): void
    {
        $report = DesignedReport::query()->find($schedule->designed_report_id) ?? throw new RuntimeException('The report of this schedule no longer exists.');
        $owner = User::query()->find($schedule->user_id) ?? throw new RuntimeException('The owner of this schedule no longer exists.');
        $tenantId = (string) $this->tenantContext->getTenantId();

        if ($owner->getRoleInTenant($tenantId) === null) {
            throw new RuntimeException('The owner of this schedule is no longer a member of the company.');
        }

        $spec = $this->engine->parse($report->dataset, $report->spec, $owner);
        $wantsDocument = in_array($schedule->delivery, ['documents', 'both'], true);

        if ($wantsDocument && ! $this->gate->allows($owner, $tenantId, 'documents', 'write')) {
            throw new RuntimeException('The owner of this schedule cannot write documents.');
        }

        $prepared = $this->exporter->prepare($spec, $owner, $schedule->format, $report, $schedule, store: true);
        $run = $prepared['run'];
        $temporary = $this->exporter->store($prepared);

        try {
            if ($wantsDocument) {
                $this->storeDocument($report, $run, $temporary, $prepared['file_name'], $owner);
            }
        } finally {
            @unlink($temporary);
        }

        if (in_array($schedule->delivery, ['email', 'both'], true)) {
            $link = URL::temporarySignedRoute('analytics.exports.download', $run->expires_at, ['run' => $run->id]);
            $failures = [];

            foreach ($schedule->recipients as $recipient) {
                try {
                    Mail::to($recipient)->locale((string) ($owner->locale ?: app()->getLocale()))->send(new ScheduledReportMail($report->name, $link, (string) $run->expires_at?->toDateString(), $run->row_count, $run->truncated));
                } catch (Throwable $exception) {
                    report($exception);
                    $failures[] = $recipient;
                }
            }

            if ($failures !== []) {
                throw new RuntimeException('The e-mail could not be sent to: '.implode(', ', $failures));
            }
        }
    }

    private function storeDocument(DesignedReport $report, ExportRun $run, string $path, string $fileName, User $owner): void
    {
        $document = $this->documents->create(
            ['title' => $report->name.' ('.now()->toDateString().')', 'document_type' => 'analytics_export', 'description' => 'Scheduled export of the report "'.$report->name.'"', 'metadata' => ['analytics_export_run_id' => $run->id, 'dataset' => $run->dataset]],
            new UploadedFile($path, $fileName, $this->formatter->mimeType($run->format), null, true),
            null,
            true,
            [],
            $owner,
        );
        $run->update(['document_id' => $document->id]);
    }
}
