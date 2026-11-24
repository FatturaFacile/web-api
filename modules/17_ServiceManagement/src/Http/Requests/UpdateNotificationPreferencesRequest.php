<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;

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
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.event' => ['required', 'string', 'distinct', Rule::in(HelpdeskTicketChanged::types())],
            'preferences.*.mail' => ['required', 'boolean'],
            'preferences.*.in_app' => ['required', 'boolean'],
        ];
    }
}
