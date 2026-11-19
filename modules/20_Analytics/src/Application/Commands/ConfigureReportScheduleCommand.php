<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use App\Models\User;

/**
 * Creates or changes the delivery schedule of a report. `$settings` carries `designed_report_id`, `name`,
 * `cron_expression`, `timezone`, `locale`, `format`, `recipient_user_ids`, `external_emails`, `delivery_channels`
 * (`email`, `telegram_chat_ids`, `dms_folder_id`, `webhook_url`), `sftp` (host, port, username, password or private_key,
 * path) and `is_active`.
 */
final readonly class ConfigureReportScheduleCommand
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(public User $user, public ?string $scheduleId, public array $settings) {}
}
