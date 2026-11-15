<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * The comment of a step of the S&OP cycle, and for an approval the acceptance of a capacity overload.
 */
final class CycleDecisionRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
            'accept_overload' => ['sometimes', 'boolean'],
        ];
    }
}
