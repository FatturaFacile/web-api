<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contract of PUT /api/v1/platform/notifications/settings (do-not-disturb: quiet hours and snooze).
 */
final class UpdateNotificationSettingsRequest extends FormRequest
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
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_start' => ['sometimes', 'date_format:H:i'],
            'quiet_end' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone:all'],
            'whatsapp_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'muted_until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
