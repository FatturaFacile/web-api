<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Billing of a milestone or of time and materials into a draft invoice: the VAT rate of its lines and, for time and
 * materials, the last day of work to include.
 */
#[OA\Schema(
    schema: 'ProjectsBillProjectRequest',
    required: ['tax_rate'],
    properties: [
        new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, maximum: 100, description: 'VAT rate of the invoice lines.'),
        new OA\Property(property: 'up_to_date', type: 'string', format: 'date', description: 'Time and materials only: bill work done up to this day (default today).'),
    ]
)]
final class BillProjectRequest extends FormRequest
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
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'up_to_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
