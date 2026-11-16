<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\RmaLine;

/**
 * The inspection of a received return: the disposition of every received line.
 */
final class RmaInspectRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
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
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity_inspected' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.disposition' => ['required', Rule::in(RmaLine::Dispositions)],
            'lines.*.warehouse_id' => ['nullable', 'uuid'],
            'lines.*.supplier_id' => ['nullable', 'uuid'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
