<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\TimeTravel\Domain\Enums\ValuationMethod;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Support\Instant;

/**
 * What-if valuation replay of the inventory at a bi-temporal coordinate.
 */
final class WhatIfReplayRequest extends FormRequest
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
            'valid_at' => ['required', 'date'],
            'known_at' => ['required', 'date'],
            'valuation_method' => ['required', 'string', Rule::in(ValuationMethod::values())],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'item_id' => ['nullable', 'string', 'max:64'],
            'simulation_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function coordinate(): BiTemporalCoordinate
    {
        return new BiTemporalCoordinate(Instant::fromInput((string) $this->validated('valid_at')), Instant::fromInput((string) $this->validated('known_at')));
    }

    public function method(): ValuationMethod
    {
        return ValuationMethod::from((string) $this->validated('valuation_method'));
    }

    public function optional(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
