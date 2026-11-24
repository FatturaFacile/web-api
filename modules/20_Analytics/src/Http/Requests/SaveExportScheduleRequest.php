<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Services\ReportExportFormatter;

/**
 * A scheduled export: either the dated kind (daily, weekly, monthly, delivered by e-mail or to the documents) or, when a
 * `cron_expression` is sent, the cron kind with time zone, locale and several delivery channels.
 */
final class SaveExportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isCron(): bool
    {
        return $this->filled('cron_expression');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->isCron() ? $this->cronRules() : $this->datedRules();
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isCron()) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $channels = (array) $this->input('delivery_channels', []);
            $hasChannel = ($channels['email'] ?? false) || ($channels['telegram_chat_ids'] ?? []) !== [] || ! empty($channels['dms_folder_id']) || ! empty($channels['webhook_url']) || $this->filled('sftp');
            $hasRecipient = (array) $this->input('recipient_user_ids', []) !== [] || (array) $this->input('external_emails', []) !== [];

            if (! $hasChannel && ! $hasRecipient) {
                $validator->errors()->add('delivery_channels', (string) __('analytics_errors.schedule.no_channel'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();

        return [
            'designed_report_id' => $data['designed_report_id'],
            'name' => $data['name'] ?? null,
            'cron_expression' => trim((string) $data['cron_expression']),
            'timezone' => $data['timezone'] ?? 'Europe/Zurich',
            'locale' => $data['locale'] ?? 'en',
            'format' => $data['format'] ?? 'xlsx',
            'recipient_user_ids' => $data['recipient_user_ids'] ?? [],
            'external_emails' => $data['external_emails'] ?? [],
            'delivery_channels' => $data['delivery_channels'] ?? [],
            'sftp' => $data['sftp'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleAttributes(): array
    {
        $data = $this->validated();
        $frequency = (string) $data['frequency'];

        return [
            'designed_report_id' => $data['designed_report_id'],
            'frequency' => $frequency,
            'day_of_week' => $frequency === 'weekly' ? (int) $data['day_of_week'] : null,
            'day_of_month' => $frequency === 'monthly' ? (int) $data['day_of_month'] : null,
            'format' => $data['format'] ?? 'csv',
            'delivery' => $data['delivery'],
            'recipients' => array_values(array_map(strtolower(...), $data['recipients'] ?? [])),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datedRules(): array
    {
        return [
            'designed_report_id' => ['required', 'uuid'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'day_of_week' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,28'],
            'format' => ['nullable', Rule::in(ReportExportFormatter::Formats)],
            'delivery' => ['required', Rule::in(ExportSchedule::Deliveries)],
            'recipients' => ['nullable', 'required_if:delivery,email', 'required_if:delivery,both', 'array', 'max:20'],
            'recipients.*' => ['email:rfc', 'max:190', 'distinct:ignore_case', $this->memberRecipient()],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cronRules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'designed_report_id' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:150'],
            'cron_expression' => ['required', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone:all'],
            'locale' => ['nullable', Rule::in(RegionalReportFormatter::Locales)],
            'format' => ['nullable', Rule::in(ReportExportFormatter::Formats)],
            'recipient_user_ids' => ['nullable', 'array', 'max:50'],
            'recipient_user_ids.*' => ['uuid', 'distinct', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)],
            'external_emails' => ['nullable', 'array', 'max:20'],
            'external_emails.*' => ['email:rfc', 'max:190', 'distinct:ignore_case', $this->externalRecipient()],
            'delivery_channels' => ['nullable', 'array'],
            'delivery_channels.email' => ['nullable', 'boolean'],
            'delivery_channels.telegram_chat_ids' => ['nullable', 'array', 'max:10'],
            'delivery_channels.telegram_chat_ids.*' => ['string', 'regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', 'distinct'],
            'delivery_channels.dms_folder_id' => ['nullable', 'uuid', Rule::exists('dms_folders', 'id')->where('tenant_id', $tenantId)],
            'delivery_channels.webhook_url' => ['nullable', 'url:https', 'max:500'],
            'sftp' => ['nullable', 'array'],
            'sftp.host' => ['required_with:sftp', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'sftp.port' => ['nullable', 'integer', 'between:1,65535'],
            'sftp.username' => ['required_with:sftp', 'string', 'max:100'],
            'sftp.password' => ['nullable', 'string', 'max:500'],
            'sftp.private_key' => ['nullable', 'string', 'max:8000'],
            'sftp.path' => ['nullable', 'string', 'max:300', 'not_regex:/\.\./'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function externalRecipient(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! (bool) config('analytics.reporting.allow_external_recipients', false)) {
                $fail('External e-mail recipients are not enabled for this company.');
            }
        };
    }

    private function memberRecipient(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ((bool) config('analytics.reporting.allow_external_recipients', false)) {
                return;
            }

            $tenantId = app(TenantContext::class)->getTenantId();
            $isMember = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $value)])
                ->whereHas('tenants', static fn ($query) => $query->where('tenants.id', $tenantId))
                ->exists();

            if (! $isMember) {
                $fail('The recipient must be a member of the company.');
            }
        };
    }
}
