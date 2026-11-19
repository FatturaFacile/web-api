<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\Privacy\BreachIncidentStatus;
use App\Enums\Privacy\BreachJurisdiction;
use App\Enums\Privacy\BreachRisk;
use App\Enums\Privacy\BreachSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BreachIncidentRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'detected_at' => ['nullable', 'date'],
            'jurisdiction' => [$required, Rule::enum(BreachJurisdiction::class)],
            'severity' => [$required, Rule::enum(BreachSeverity::class)],
            'risk_to_rights_and_freedoms' => [$required, Rule::enum(BreachRisk::class)],
            'affected_subjects_count' => ['nullable', 'integer', 'min:0'],
            'affected_data_categories' => ['nullable', 'array'],
            'affected_data_categories.*' => ['string', 'max:120'],
            'containment_measures' => ['nullable', 'string', 'max:10000'],
            'remediation_plan' => ['nullable', 'string', 'max:10000'],
            'authority_notified_at' => ['nullable', 'date'],
            'authority_reference_number' => ['nullable', 'string', 'max:120'],
            'subjects_notified_at' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(BreachIncidentStatus::class)],
        ];
    }
}
