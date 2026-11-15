<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * A compensation cycle. The status moves through the open and close actions, never through this payload.
 */
final class CompCycleRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:150'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'currency' => ['required', 'string', 'size:3', 'alpha:ascii'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => array_filter(['required', 'date_format:Y-m-d', $this->filled('starts_on') ? 'after_or_equal:starts_on' : null]),
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
