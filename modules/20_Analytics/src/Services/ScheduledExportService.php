<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Pivot\DesignedReportPlan;
use Modules\Analytics\Scheduling\CronSchedule;
use Modules\Analytics\Scheduling\ReportDeliveryService;
use RuntimeException;
use Throwable;

/**
 * Runs the scheduled exports of the active company. A schedule runs with the permissions of its owner at the moment of
 * the run: a user who lost the dataset (or the company) gets a failed run, never the data.
 *
 * Two kinds of schedule share the table: the daily, weekly and monthly ones (a date, run once a day by the daily
 * command; the next date moves past today whatever the outcome, so running the command twice does not mail twice) and
 * the cron ones (an instant in UTC, claimed by {@see claimDueCron()} every minute before the run is queued, so a slow
 * run is never queued twice). A failure is recorded on the schedule instead of being retried every hour.
 *
 * Delivery goes to the channels of the schedule through {@see ReportDeliveryService}: e-mail, Telegram, the document
 * library, a signed webhook and SFTP. A pivot report is exported as its matrix.
 */
final readonly class ScheduledExportService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportExporter $exporter,
        private TenantClock $clock,
        private TenantContext $tenantContext,
        private ReportDeliveryService $delivery,
        private DesignedReportPlan $plans,
        private CronSchedule $cron,
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
     * Runs every active dated schedule of the company that is due (the cron ones go through the queue).
     *
     * @return array{succeeded: int, failed: int}
     */
    public function runDue(): array
    {
        $today = $this->clock->today();
        $outcome = ['succeeded' => 0, 'failed' => 0];
        $due = ExportSchedule::query()->where('is_active', true)->where('frequency', '<>', 'cron')->whereDate('next_run_on', '<=', $today->toDateString())->get();

        foreach ($due as $schedule) {
            $this->runSchedule($schedule, $today) ? $outcome['succeeded']++ : $outcome['failed']++;
        }

        return $outcome;
    }

    /**
     * Moves the next run of every due cron schedule of the company past now and returns them, so each is queued once.
     *
     * @return list<ExportSchedule>
     */
    public function claimDueCron(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $claimed = [];

        foreach (ExportSchedule::query()->where('is_active', true)->where('frequency', 'cron')->where('next_run_at', '<=', $now)->get() as $schedule) {
            $next = $this->nextCronRun($schedule, $now);
            $won = ExportSchedule::query()->whereKey($schedule->id)->where('next_run_at', $schedule->next_run_at)->update(['next_run_at' => $next]);

            if ($won === 1) {
                $claimed[] = $schedule->refresh();
            }
        }

        return $claimed;
    }

    /**
     * The next instant of a cron schedule after the given one, in UTC.
     */
    public function nextCronRun(ExportSchedule $schedule, ?CarbonImmutable $after = null): CarbonImmutable
    {
        return $this->cron->nextRunAfter((string) $schedule->cron_expression, (string) $schedule->timezone, $after ?? CarbonImmutable::now('UTC'));
    }

    /**
     * Runs one schedule now and records the outcome on it. Dated schedules also move to their next date (`$advance`);
     * a cron schedule was already moved when it was claimed, and a manual run leaves the calendar alone.
     */
    public function runSchedule(ExportSchedule $schedule, ?CarbonImmutable $today = null, bool $advance = true): bool
    {
        $today ??= $this->clock->today();
        $error = null;

        try {
            $this->deliver($schedule);
        } catch (Throwable $exception) {
            report($exception);
            $error = mb_substr($exception->getMessage(), 0, 500);
        }

        $changes = ['last_run_at' => now(), 'last_status' => $error === null ? 'ok' : 'failed', 'last_error' => $error];

        if ($advance && $schedule->frequency !== 'cron') {
            $changes['next_run_on'] = $this->onOrAfter($today->addDay(), $schedule->frequency, $schedule->day_of_week, $schedule->day_of_month)->toDateString();
        }

        $schedule->update($changes);

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

        $plan = $this->plans->forReport($report, $owner);
        $channels = $this->channels($schedule);
        $locale = $schedule->locale === null ? null : RegionalReportFormatter::normalise($schedule->locale);
        $language = $locale === null ? (string) ($owner->locale ?: app()->getLocale()) : (new RegionalReportFormatter($locale))->language();
        $prepared = $plan['pivot'] === null
            ? $this->exporter->prepare($plan['spec'], $owner, $schedule->format, $report, $schedule, store: true, locale: $locale)
            : $this->exporter->preparePivot($plan['spec'], $plan['pivot'], $plan['calculated'], $owner, $schedule->format, $report, $schedule, store: true, locale: $locale);
        $run = $prepared['run'];
        $temporary = $this->exporter->store($prepared);

        try {
            $failures = $this->delivery->deliver($this->withChannels($schedule, $channels), $report, $run->refresh(), $temporary, $prepared['file_name'], $prepared['mime'], $owner, $language);
        } finally {
            @unlink($temporary);
        }

        if ($failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }
    }

    /**
     * The channels of the schedule: its own for the new ones, derived from the `delivery` choice for the legacy ones.
     *
     * @return array<string, mixed>
     */
    private function channels(ExportSchedule $schedule): array
    {
        $channels = (array) ($schedule->delivery_channels ?? []);

        if ($channels === [] || $schedule->frequency !== 'cron') {
            $channels['email'] = $channels['email'] ?? in_array($schedule->delivery, ['email', 'both'], true);
            $channels['dms'] = $channels['dms'] ?? in_array($schedule->delivery, ['documents', 'both'], true);
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $channels
     */
    private function withChannels(ExportSchedule $schedule, array $channels): ExportSchedule
    {
        $copy = $schedule->replicate();
        $copy->id = $schedule->id;
        $copy->delivery_channels = $channels;
        $copy->secret_config_encrypted = $schedule->secret_config_encrypted;

        return $copy;
    }
}
