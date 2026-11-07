<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateHelpdeskSettingsRequest extends FormRequest
{
    use TenantScopedRules;

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
            'reopen_window_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'business_hours_calendar_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_business_hours_calendars')],
            'sla_warning_percent' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'csat_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
