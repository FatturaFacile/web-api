<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Platform\Services\NotificationPreferenceService;

/**
 * Contract of PUT /api/v1/platform/notifications/preferences (mirrored by the frontend).
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:100'],
            'preferences.*.channel' => ['required', 'string', Rule::in(config('platform.notification_channels'))],
            'preferences.*.category' => ['required', 'string', Rule::in(config('platform.notification_categories'))],
            'preferences.*.enabled' => ['required_without:preferences.*.frequency', 'boolean'],
            'preferences.*.frequency' => ['required_without:preferences.*.enabled', 'string', Rule::in(NotificationPreferenceService::FREQUENCIES)],
        ];
    }
}
