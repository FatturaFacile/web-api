<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\RecurringCashItem;
use OpenApi\Attributes as OA;

/**
 * A recurring cash flow projected by the liquidity forecast.
 */
#[OA\Schema(
    schema: 'TreasuryStoreRecurringCashItemRequest',
    required: ['name', 'direction', 'amount', 'currency', 'frequency', 'next_date'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'category', type: 'string', maxLength: 50, example: 'rent'),
        new OA\Property(property: 'direction', type: 'string', enum: ['inflow', 'outflow']),
        new OA\Property(property: 'amount', type: 'string', example: '2400.00'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3),
        new OA\Property(property: 'frequency', type: 'string', enum: RecurringCashItem::Frequencies),
        new OA\Property(property: 'next_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
    ]
)]
final class StoreRecurringCashItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'category' => ['sometimes', 'string', 'max:50'],
            'direction' => ['required', Rule::in(['inflow', 'outflow'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'frequency' => ['required', Rule::in(RecurringCashItem::Frequencies)],
            'next_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:next_date'],
        ];
    }
}
