<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting of the periods that ended by a date, for one lease or for every active lease of the company.
 */
final class PostLeasePeriodsRequest extends FormRequest
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
            'through_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
