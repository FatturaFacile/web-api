<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * One scan of any terminal operation. Which optional code is needed depends on the operation, named by the route.
 */
final class TerminalScanRequest extends FormRequest
{
    use ValidatesTenantRecords;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $operation = substr((string) $this->route()?->getName(), strlen('inventory.terminal.'));
        $needsTwoBins = $operation === 'transfer';

        return [
            'device_id' => ['required', 'string', 'max:100'],
            'client_scan_id' => ['required', 'string', 'max:100'],
            'item_code' => ['required', 'string', 'max:500'],
            'bin_code' => [$needsTwoBins ? 'nullable' : 'required', 'string', 'max:100'],
            'from_bin_code' => [$needsTwoBins ? 'required' : 'nullable', 'string', 'max:100'],
            'to_bin_code' => [$needsTwoBins ? 'required' : 'nullable', 'string', 'max:100'],
            'destination_bin_code' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', $operation === 'receipt' || $needsTwoBins ? 'gt:0' : 'min:0'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'expiration_date' => ['nullable', 'date'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'pick_line_id' => [$operation === 'pick' ? 'required' : 'nullable', 'uuid', $this->tenantExists('stock_pick_lines')],
            'session_id' => [$operation === 'count' ? 'required' : 'nullable', 'uuid', $this->tenantExists('inventory_count_sessions')],
            'purchase_receipt_id' => ['nullable', 'uuid', $this->tenantExists('purchase_receipts')],
        ];
    }
}
