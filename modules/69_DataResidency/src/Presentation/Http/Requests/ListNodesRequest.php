<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\SovereignServiceType;

final class ListNodesRequest extends FormRequest
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
            'jurisdiction_zone' => ['nullable', Rule::enum(JurisdictionZone::class)],
            'service_type' => ['nullable', Rule::enum(SovereignServiceType::class)],
        ];
    }
}
