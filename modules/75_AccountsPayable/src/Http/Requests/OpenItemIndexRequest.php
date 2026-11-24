<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AccountsPayable\Enums\OpenItemKind;
use Modules\AccountsPayable\Enums\OpenItemStatus;

/**
 * Filters of the supplier open items list.
 */
final class OpenItemIndexRequest extends FormRequest
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
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(OpenItemStatus::values())],
            'kind' => ['nullable', Rule::enum(OpenItemKind::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'as_of' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'only_payable' => ['nullable', 'boolean'],
            'skonto_eligible' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function asOf(): CarbonImmutable
    {
        return $this->filled('as_of') ? CarbonImmutable::parse((string) $this->input('as_of'))->startOfDay() : CarbonImmutable::today();
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 25);
    }

    /**
     * @return array{supplier_id: ?string, status: ?string, kind: ?string, currency: ?string, due_from: ?string, due_to: ?string, search: ?string, only_payable: ?bool, skonto_eligible: ?bool}
     */
    public function filters(): array
    {
        return [
            'supplier_id' => $this->filled('supplier_id') ? (string) $this->input('supplier_id') : null,
            'status' => $this->filled('status') ? (string) $this->input('status') : null,
            'kind' => $this->filled('kind') ? (string) $this->input('kind') : null,
            'currency' => $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null,
            'due_from' => $this->filled('due_from') ? (string) $this->input('due_from') : null,
            'due_to' => $this->filled('due_to') ? (string) $this->input('due_to') : null,
            'search' => $this->filled('search') ? (string) $this->input('search') : null,
            'only_payable' => $this->has('only_payable') ? $this->boolean('only_payable') : null,
            'skonto_eligible' => $this->has('skonto_eligible') ? $this->boolean('skonto_eligible') : null,
        ];
    }
}
