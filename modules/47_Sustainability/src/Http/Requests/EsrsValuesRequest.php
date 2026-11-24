<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Models\EsgEsrsDatapointValue;

/**
 * The values of several ESRS data points for a reporting period.
 */
final class EsrsValuesRequest extends FormRequest
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
            'values' => ['required', 'array', 'min:1', 'max:200'],
            'values.*.datapoint_id' => ['required', 'uuid'],
            'values.*.status' => ['nullable', Rule::in(EsgEsrsDatapointValue::Statuses)],
            'values.*.value_number' => ['nullable', 'numeric', 'min:-999999999999999999', 'max:999999999999999999'],
            'values.*.value_text' => ['nullable', 'string', 'max:20000'],
            'values.*.value_boolean' => ['nullable', 'boolean'],
            'values.*.omission_reason' => ['nullable', 'string', 'max:2000'],
            'values.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
