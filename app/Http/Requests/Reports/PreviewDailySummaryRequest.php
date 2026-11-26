<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewDailySummaryRequest extends FormRequest
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
            'language' => ['nullable', 'string', Rule::in(NotificationPreference::SUPPORTED_LANGUAGES)],
            'include_dashboard' => ['nullable', 'boolean'],
            'include_billing' => ['nullable', 'boolean'],
            'include_deadlines' => ['nullable', 'boolean'],
            'include_crm' => ['nullable', 'boolean'],
            'include_calendar' => ['nullable', 'boolean'],
            'include_inventory' => ['nullable', 'boolean'],
            'include_support' => ['nullable', 'boolean'],
            'include_news' => ['nullable', 'boolean'],
            'include_insights' => ['nullable', 'boolean'],
        ];
    }
}
