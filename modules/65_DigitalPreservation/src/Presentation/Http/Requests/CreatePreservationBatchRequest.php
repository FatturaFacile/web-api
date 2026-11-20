<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;

final class CreatePreservationBatchRequest extends FormRequest
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
            'document_category' => ['required', Rule::enum(DocumentCategory::class)],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'jurisdiction' => ['nullable', Rule::enum(JurisdictionMode::class)],
        ];
    }
}
