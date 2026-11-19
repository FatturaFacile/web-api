<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class CallOffRequest extends FormRequest
{
    use TenantScopedRules;

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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.blanket_agreement_line_id' => ['required', 'uuid', $this->tenantExists('blanket_agreement_lines')],
            'lines.*.quantity' => ['required_without:lines.*.amount', 'nullable', 'numeric', 'gt:0'],
            'lines.*.amount' => ['required_without:lines.*.quantity', 'nullable', 'numeric', 'gt:0'],
            'requested_delivery_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'notes' => ['nullable', 'string'],
        ];
    }
}
