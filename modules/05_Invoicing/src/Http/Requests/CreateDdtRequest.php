<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Services\SalesOrderFulfillmentService;

/**
 * Partial or full shipment of a sales order: the quantity per line (line id => quantity), what to do with the residual
 * of a partially shipped line and the transport data of the delivery note.
 */
final class CreateDdtRequest extends FormRequest
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
            'lines' => ['nullable', 'array'],
            'lines.*' => ['numeric', 'gt:0'],
            'residual_mode' => ['nullable', Rule::in(SalesOrderFulfillmentService::ResidualModes)],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'ddt_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
