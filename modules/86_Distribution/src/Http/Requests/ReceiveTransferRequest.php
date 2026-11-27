<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;
use Modules\Distribution\Services\TransferWorkflowService;

/**
 * Receipt of a transfer: good and damaged units per line and how the missing units are resolved.
 */
final class ReceiveTransferRequest extends FormRequest
{
    use ScopesToTenant;

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
            'lines' => ['sometimes', 'array', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct', $this->tenantExists('transfer_order_lines')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.destination_bin_id' => ['nullable', 'uuid', $this->tenantExists('warehouse_bins')],
            'final' => ['sometimes', 'boolean'],
            'resolution' => ['nullable', 'string', Rule::in(TransferWorkflowService::RESOLUTIONS)],
        ];
    }
}
