<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Models\ExportSchedule;

/**
 * A recurring export. The accountant archive needs a period, because it cannot cover all the years at once.
 */
final class StoreExportScheduleRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
            'dataset' => ['required', 'string', Rule::in(ExportJob::Datasets)],
            'frequency' => ['required', 'string', Rule::in(ExportSchedule::Frequencies)],
            'period' => ['required', 'string', Rule::in($this->input('dataset') === 'accountant_archive' ? ['previous_month'] : ExportSchedule::Periods)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
