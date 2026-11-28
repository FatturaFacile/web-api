<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

/**
 * Read-only listing filters (every filter is optional and validated by shape only).
 */
final class ViewRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'viewAny';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'breeder_id' => ['nullable', 'uuid'],
            'tank_id' => ['nullable', 'uuid'],
            'vat_batch_id' => ['nullable', 'uuid'],
            'rack_id' => ['nullable', 'uuid'],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['nullable', 'string', 'max:20'],
            'grade' => ['nullable', 'string', 'max:20'],
            'cheese_type' => ['nullable', 'string', 'max:40'],
            'care_type' => ['nullable', 'string', 'max:20'],
            'authority' => ['nullable', 'in:dbmilch,sian'],
            'overdue' => ['nullable', 'boolean'],
            'age_min' => ['nullable', 'numeric', 'min:0'],
            'age_max' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }
}
