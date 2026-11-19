<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DigitalPreservation\Domain\Enums\AutoSealFrequency;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;

final class UpdatePreservationConfigurationRequest extends FormRequest
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
            'jurisdiction' => ['required', Rule::enum(JurisdictionMode::class)],
            'provider' => ['required', Rule::enum(PreservationProvider::class)],
            'credentials' => ['nullable', 'array'],
            'preservation_officer_name' => ['nullable', 'string', 'max:200'],
            'preservation_officer_tax_code' => ['nullable', 'string', 'max:32'],
            'tsa_url' => ['nullable', 'url:https', 'max:500'],
            'tsa_credentials' => ['nullable', 'array'],
            'auto_seal_frequency' => ['required', Rule::enum(AutoSealFrequency::class)],
            'default_retention_years' => ['required', 'integer', 'min:10', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
