<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;

/**
 * A competitor award of the benchmark archive.
 */
final class SaveBenchmarkRequest extends FormRequest
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
        $partial = $this->isMethod('PUT') ? 'sometimes' : 'required';

        return [
            'competitor_name' => [$partial, 'string', 'max:200'],
            'contracting_authority_name' => [$partial, 'string', 'max:200'],
            'tender_id' => ['nullable', 'uuid'],
            'cpv_code' => ['nullable', 'string', 'regex:/^\d{8}(-\d)?$/'],
            'jurisdiction' => ['nullable', Rule::in(Jurisdiction::values())],
            'award_date' => ['nullable', 'date'],
            'base_auction_amount' => ['nullable', 'numeric', 'min:0'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0'],
            'offered_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'technical_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'economic_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_winner' => ['sometimes', 'boolean'],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
