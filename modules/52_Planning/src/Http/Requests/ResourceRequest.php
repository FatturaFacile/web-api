<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * A capacity resource with its default capacity per month.
 */
final class ResourceRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'work_center_id' => ['nullable', 'uuid'],
            'unit' => ['required', 'string', 'max:20'],
            'default_capacity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
