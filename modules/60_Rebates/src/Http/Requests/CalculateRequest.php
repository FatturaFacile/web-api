<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

/**
 * Date up to which the invoiced volume is counted; today when left out.
 */
final class CalculateRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['as_of' => ['nullable', 'date_format:Y-m-d']];
    }
}
