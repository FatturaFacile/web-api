<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Counts taken on a device and sent together (offline sync): every entry carries a client entry id, so a resend of
 * the batch changes nothing.
 */
final class RecordCountsBatchRequest extends FormRequest
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
        return [
            'device_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.client_entry_id' => ['required', 'uuid'],
            'entries.*.session_line_id' => ['required', 'uuid', $this->tenantExists('inventory_count_lines')],
            'entries.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'entries.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
