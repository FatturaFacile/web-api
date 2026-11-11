<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Services\ReportExportFormatter;

/**
 * Validates a scheduled export. Recipients are members of the company unless the installation allows external ones.
 */
final class SaveExportScheduleRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policies.
     */
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
            'designed_report_id' => ['required', 'uuid'],
            'frequency' => ['required', Rule::in(ExportSchedule::Frequencies)],
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
     * The validated attributes, with the fields that do not apply to the frequency cleared.
     *
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
