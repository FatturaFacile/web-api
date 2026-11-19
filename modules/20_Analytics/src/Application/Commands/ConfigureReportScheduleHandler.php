<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Scheduling\CronSchedule;
use Modules\Analytics\Semantic\ReportEngine;

/**
 * Validates the cron expression (and refuses one that runs more often than every five minutes), computes `next_run_at`
 * on the wall clock of the chosen time zone, and stores the delivery channels. The SFTP credentials and the signing secret
 * of the webhook are kept encrypted and never returned; the webhook secret is generated here and handed back once, so the
 * receiver can verify the signature.
 *
 * @phpstan-type Outcome array{schedule: ExportSchedule, webhook_secret: ?string}
 */
final readonly class ConfigureReportScheduleHandler
{
    public function __construct(private CronSchedule $cron, private ReportEngine $engine) {}

    /**
     * @return array{schedule: ExportSchedule, webhook_secret: ?string}
     */
    public function handle(ConfigureReportScheduleCommand $command): array
    {
        $settings = $command->settings;
        $report = DesignedReport::query()->visibleTo($command->user)->find((string) $settings['designed_report_id']);

        if ($report === null) {
            throw ValidationException::withMessages(['designed_report_id' => ['The report does not exist or is not shared with you.']]);
        }

        $this->engine->datasetFor($report->dataset, $command->user);

        $expression = (string) $settings['cron_expression'];
        $timezone = (string) ($settings['timezone'] ?? 'Europe/Zurich');

        try {
            $this->cron->assertValid($expression, $timezone);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cron_expression' => [(string) __($exception->getMessage())]]);
        }

        $schedule = $command->scheduleId === null ? new ExportSchedule(['user_id' => $command->user->id]) : ExportSchedule::query()->findOrFail($command->scheduleId);

        if ($schedule->exists) {
            Gate::forUser($command->user)->authorize('manage', $schedule);
        }

        $channels = $this->channels((array) ($settings['delivery_channels'] ?? []));
        $secrets = $this->secretsOf($schedule);
        $webhookSecret = null;

        if (($channels['webhook_url'] ?? null) === null) {
            unset($secrets['webhook_secret']);
        } elseif (empty($secrets['webhook_secret'])) {
            $webhookSecret = 'whsec_'.Str::random(40);
            $secrets['webhook_secret'] = $webhookSecret;
        }

        $sftp = $settings['sftp'] ?? null;

        if ($sftp === null || $sftp === []) {
            unset($secrets['sftp']);
        } else {
            $previous = (array) ($secrets['sftp'] ?? []);
            $previousPassword = $previous['password'] ?? null;
            $previousKey = $previous['private_key'] ?? null;
            $secrets['sftp'] = array_filter([
                'host' => (string) $sftp['host'],
                'port' => (int) ($sftp['port'] ?? 22),
                'username' => (string) $sftp['username'],
                'password' => $sftp['password'] ?? $previousPassword,
                'private_key' => $sftp['private_key'] ?? $previousKey,
                'path' => (string) ($sftp['path'] ?? '/'),
            ], static fn (mixed $value): bool => $value !== null);
        }

        $locale = isset($settings['locale']) ? RegionalReportFormatter::normalise((string) $settings['locale']) : 'en';

        $schedule->fill([
            'designed_report_id' => $report->id,
            'name' => $settings['name'] ?? null,
            'frequency' => 'cron',
            'day_of_week' => null,
            'day_of_month' => null,
            'cron_expression' => $expression,
            'timezone' => $timezone,
            'locale' => $locale,
            'format' => (string) ($settings['format'] ?? 'xlsx'),
            'delivery' => 'email',
            'recipients' => [],
            'recipient_user_ids' => array_values(array_unique(array_map('strval', (array) ($settings['recipient_user_ids'] ?? [])))),
            'external_emails' => array_values(array_unique(array_map(static fn (mixed $email): string => strtolower((string) $email), (array) ($settings['external_emails'] ?? [])))),
            'delivery_channels' => $channels,
            'secret_config_encrypted' => $secrets === [] ? null : $secrets,
            'is_active' => (bool) ($settings['is_active'] ?? true),
            'next_run_on' => null,
            'next_run_at' => $this->cron->nextRunAfter($expression, $timezone, CarbonImmutable::now('UTC')),
        ])->save();

        return ['schedule' => $schedule, 'webhook_secret' => $webhookSecret];
    }

    /**
     * The encrypted secrets of the schedule: `webhook_secret` and `sftp` (host, port, username, password, private_key, path).
     *
     * @return array<string, mixed>
     */
    private function secretsOf(ExportSchedule $schedule): array
    {
        return $schedule->secret_config_encrypted ?? [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function channels(array $raw): array
    {
        return [
            'email' => (bool) ($raw['email'] ?? false),
            'telegram_chat_ids' => array_values(array_unique(array_map('strval', (array) ($raw['telegram_chat_ids'] ?? [])))),
            'dms_folder_id' => ($raw['dms_folder_id'] ?? null) === null || $raw['dms_folder_id'] === '' ? null : (string) $raw['dms_folder_id'],
            'webhook_url' => ($raw['webhook_url'] ?? null) === null || $raw['webhook_url'] === '' ? null : (string) $raw['webhook_url'],
        ];
    }
}
