<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\InspectionOutcome;
use Modules\AdventureParks\Domain\Enums\InspectionType;
use Modules\AdventureParks\Models\PpeInspectionLog;

/**
 * Inspection of a PPE item: the annual EN 365 inspection needs the license number of the competent person.
 */
final class InspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operate', PpeInspectionLog::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inspection_type' => ['required', Rule::enum(InspectionType::class)],
            'outcome' => ['required', Rule::enum(InspectionOutcome::class)],
            'inspected_on' => ['required', 'date_format:Y-m-d'],
            'inspector_name' => ['nullable', 'string', 'max:150'],
            'inspector_license_number' => [Rule::requiredIf(fn (): bool => $this->input('inspection_type') === InspectionType::AnnualEn365->value), 'nullable', 'string', 'max:60'],
            'signature' => ['nullable', 'string', 'max:'.(int) config('adventure_parks.signature_max_length', 700000)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['nullable', 'array', 'max:50'],
            'checklist.*.key' => ['required', 'string', 'max:60'],
            'checklist.*.ok' => ['required', 'boolean'],
            'next_due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inspected_on'],
        ];
    }
}
