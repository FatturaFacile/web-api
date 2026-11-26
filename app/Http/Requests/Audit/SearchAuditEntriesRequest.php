<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Http\Requests\Audit\Concerns\ValidatesAuditWindow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Search of the probative audit ledger (Mod 005): dates as Y-m-d, a window of at most 366 days, cursor pagination.
 */
final class SearchAuditEntriesRequest extends FormRequest
{
    use ValidatesAuditWindow;

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('audit-ledger.search');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cursor' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ] + $this->filterRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->assertWindowWithinLimit($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter($this->only(['from', 'to']), static fn (mixed $value): bool => is_string($value) && $value !== '') + $this->presentFilters();
    }
}
