<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of the maturity analysis: the reporting date (default today) and, optionally, one currency.
 */
final class MaturityReportRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', Rule::in(config('leases.currencies'))],
        ];
    }
}
