<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Beekeeping\Domain\Enums\Country;

/**
 * Generates a census draft (year + country) or, with `submission_reference` on the submit route, records its submission.
 */
final class GenerateCensusRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'census_year' => ['required', 'integer', 'between:2000,2100'],
            'country' => ['required', Rule::enum(Country::class)],
            'submission_reference' => ['nullable', 'string', 'max:80'],
        ];
    }
}
