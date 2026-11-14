<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A pagoPA payment notice for a receivable.
 */
#[OA\Schema(
    schema: 'TreasuryStorePagoPaNoticeRequest',
    required: ['debtor_name', 'amount', 'due_date', 'causale', 'creditor_fiscal_code'],
    properties: [
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reference_type', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'reference_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'debtor_name', type: 'string', maxLength: 150),
        new OA\Property(property: 'debtor_fiscal_code', type: 'string', maxLength: 16, nullable: true),
        new OA\Property(property: 'amount', type: 'string', example: '122.00'),
        new OA\Property(property: 'currency', type: 'string', default: 'EUR', enum: ['EUR']),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'causale', type: 'string', maxLength: 140),
        new OA\Property(property: 'creditor_fiscal_code', description: 'Fiscal code of the creditor entity (11 digits)', type: 'string', pattern: '^[0-9]{11}$'),
    ]
)]
final class StorePagoPaNoticeRequest extends FormRequest
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
            'invoice_id' => ['nullable', 'uuid'],
            'reference_type' => ['nullable', 'string', 'max:100', 'required_with:reference_id'],
            'reference_id' => ['nullable', 'uuid', 'required_with:reference_type'],
            'debtor_name' => ['required', 'string', 'max:150'],
            'debtor_fiscal_code' => ['nullable', 'string', 'alpha_num', 'min:11', 'max:16'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'in:EUR'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'causale' => ['required', 'string', 'max:140'],
            'creditor_fiscal_code' => ['required', 'string', 'regex:/^[0-9]{11}$/'],
        ];
    }
}
