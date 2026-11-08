<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class RecordCountRequest extends FormRequest
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
            'session_line_id' => ['required', 'uuid', $this->tenantExists('inventory_count_lines')],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
