<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * The daily closing of a cash register: the day and the cash counted.
 */
#[OA\Schema(
    schema: 'TreasuryCloseCashRegisterRequest',
    required: ['closing_date', 'counted_balance'],
    properties: [
        new OA\Property(property: 'closing_date', type: 'string', format: 'date'),
        new OA\Property(property: 'counted_balance', type: 'string', example: '312.40'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
final class CloseCashRegisterRequest extends FormRequest
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
            'closing_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'counted_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
