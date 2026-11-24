<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\InvoiceMatchType;

/**
 * Filters of the matching workbench and of the blocked discrepancy queue.
 */
final class InvoiceMatchRunIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(InvoiceMatchStatus::class)],
            'match_type' => ['nullable', Rule::enum(InvoiceMatchType::class)],
            'supplier_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'queue' => ['nullable', 'string', 'in:blocked'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
