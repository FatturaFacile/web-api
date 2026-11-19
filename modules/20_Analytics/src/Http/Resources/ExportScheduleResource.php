<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Analytics\Models\ExportSchedule;

/**
 * API representation of a scheduled export. Secrets (SFTP password or key, webhook signing secret) are never returned, only
 * whether they are set.
 *
 * @mixin ExportSchedule
 */
final class ExportScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $secrets = (array) ($this->secret_config_encrypted ?? []);
        $sftp = (array) ($secrets['sftp'] ?? []);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'designed_report_id' => $this->designed_report_id,
            'name' => $this->name,
            'frequency' => $this->frequency,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'cron_expression' => $this->cron_expression,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'format' => $this->format,
            'delivery' => $this->delivery,
            'recipients' => $this->recipients,
            'recipient_user_ids' => $this->recipient_user_ids,
            'external_emails' => $this->external_emails,
            'delivery_channels' => $this->delivery_channels,
            'sftp' => $sftp === [] ? null : ['host' => $sftp['host'] ?? null, 'port' => $sftp['port'] ?? 22, 'username' => $sftp['username'] ?? null, 'path' => $sftp['path'] ?? '/', 'secret_set' => ! empty($sftp['password']) || ! empty($sftp['private_key'])],
            'webhook_secret_set' => ! empty($secrets['webhook_secret']),
            'is_active' => $this->is_active,
            'next_run_on' => $this->next_run_on?->toDateString(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_status' => $this->last_status,
            'last_error' => $this->last_error,
        ];
    }
}
