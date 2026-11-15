<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * The capacity an item consumes on a resource per unit supplied.
 */
final class ResourceRateRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'item_code' => ['required', 'string', 'max:60'],
            'load_per_unit' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ]);
    }
}
