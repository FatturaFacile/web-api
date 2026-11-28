<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Support\Instant;

/**
 * Coordinate (valid_at, known_at) and the filters of the domain to reconstruct.
 */
final class StateRequest extends FormRequest
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
            'known_at' => ['required', 'date'],
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

    public function coordinate(): BiTemporalCoordinate
    {
        return new BiTemporalCoordinate(Instant::fromInput((string) $this->validated('valid_at')), Instant::fromInput((string) $this->validated('known_at')));
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
