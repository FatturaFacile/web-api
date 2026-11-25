<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Services\SupplierRatingService;

/**
 * Period, filters, sorting and page of the supplier rating ranking; the period defaults to the current year to date.
 */
final class SrmScorecardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'tier' => ['nullable', 'string', 'in:A,B,C,D'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', SupplierRatingService::SortFields)],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function from(): string
    {
        return (string) ($this->validated('from') ?? now()->startOfYear()->toDateString());
    }

    public function to(): string
    {
        return (string) ($this->validated('to') ?? now()->toDateString());
    }

    /**
     * @return array{search: ?string, tier: ?string, sort: string, direction: string, page: int, per_page: int}
     */
    public function listing(): array
    {
        return [
            'search' => $this->validated('search') !== null ? (string) $this->validated('search') : null,
            'tier' => $this->validated('tier') !== null ? (string) $this->validated('tier') : null,
            'sort' => (string) ($this->validated('sort') ?? 'score'),
            'direction' => (string) ($this->validated('direction') ?? 'desc'),
            'page' => (int) ($this->validated('page') ?? 1),
            'per_page' => (int) ($this->validated('per_page') ?? 20),
        ];
    }
}
