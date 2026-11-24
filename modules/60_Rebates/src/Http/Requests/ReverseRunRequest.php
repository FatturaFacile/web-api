<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

/**
 * The reason a posted accrual run is reversed.
 */
final class ReverseRunRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:200']];
    }
}
