<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reference date of the cash-discount figures of one open item.
 */
final class ShowOpenItemRequest extends FormRequest
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
        return ['as_of' => ['nullable', 'date']];
    }

    public function asOf(): CarbonImmutable
    {
        return $this->filled('as_of') ? CarbonImmutable::parse((string) $this->input('as_of'))->startOfDay() : CarbonImmutable::today();
    }
}
