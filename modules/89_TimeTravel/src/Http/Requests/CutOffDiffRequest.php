<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Support\Instant;

/**
 * One valid-time cut-off compared between two moments of knowledge.
 */
final class CutOffDiffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', Rule::in(QueryDomain::values())],
            'valid_at' => ['required', 'date'],
            'known_at_from' => ['required', 'date'],
            'known_at_to' => ['required', 'date', 'after_or_equal:known_at_from'],
            'account_id' => ['nullable', 'string', 'max:64', Rule::requiredIf(fn (): bool => $this->input('domain') === QueryDomain::AccountLedger->value)],
            'ledger_type' => ['nullable', 'string', 'max:32'],
            'partner_type' => ['nullable', 'string', Rule::in(['customer', 'supplier'])],
            'partner_id' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'item_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function domain(): QueryDomain
    {
        return QueryDomain::from((string) $this->validated('domain'));
    }

    public function instant(string $key): CarbonImmutable
    {
        return Instant::fromInput((string) $this->validated($key));
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['account_id', 'ledger_type', 'partner_type', 'partner_id', 'warehouse_id', 'item_id']),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }
}
