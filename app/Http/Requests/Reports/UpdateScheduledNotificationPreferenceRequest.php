<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\NotificationPreference;
use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateScheduledNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'string', Rule::in(NotificationPreference::FREQUENCIES)],
            'custom_cron' => ['nullable', 'string', 'max:120', 'required_if:frequency,custom_cron'],
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'language' => ['required', 'string', Rule::in(NotificationPreference::SUPPORTED_LANGUAGES)],
            'include_dashboard' => ['required', 'boolean'],
            'include_billing' => ['required', 'boolean'],
            'include_deadlines' => ['required', 'boolean'],
            'include_crm' => ['required', 'boolean'],
            'include_calendar' => ['required', 'boolean'],
            'include_inventory' => ['required', 'boolean'],
            'include_support' => ['required', 'boolean'],
            'include_news' => ['required', 'boolean'],
            'include_insights' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('frequency') === NotificationPreference::FREQUENCY_CUSTOM_CRON) {
                $cron = (string) $this->input('custom_cron', '');
                if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
                    $validator->errors()->add('custom_cron', 'The custom cron expression is invalid.');
                }
            }
        });
    }
}
