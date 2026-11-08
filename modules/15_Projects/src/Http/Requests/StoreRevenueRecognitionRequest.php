<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A revenue recognition run for an accounting date; the completion percentage may be stated only under the
 * percentage-of-completion method.
 */
#[OA\Schema(
    schema: 'ProjectsStoreRevenueRecognitionRequest',
    required: ['accounting_period_date'],
    properties: [
        new OA\Property(property: 'accounting_period_date', type: 'string', format: 'date'),
        new OA\Property(property: 'override_completion_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true),
    ]
)]
final class StoreRevenueRecognitionRequest extends FormRequest
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
            'accounting_period_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'override_completion_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
