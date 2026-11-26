<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Http\Requests\Audit\Concerns\ValidatesAuditWindow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Request of an export package of the audit ledger: a required window of at most 366 days, the filters of the search and
 * the Idempotency-Key header (a repeated request with the same key returns the first export).
 */
final class ExportAuditRequest extends FormRequest
{
    use ValidatesAuditWindow;

    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('audit-ledger.export');
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        if (is_string($key) && $key !== '') {
            $this->merge(['idempotency_key' => $key]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
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
        return $this->presentFilters();
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
