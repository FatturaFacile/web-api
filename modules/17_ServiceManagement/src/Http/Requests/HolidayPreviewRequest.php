<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Services\Helpdesk\HolidayCatalog;

/**
 * Preview of the public holidays a business hours calendar skips: country (CH or IT), optional canton or region, and year.
 */
final class HolidayPreviewRequest extends FormRequest
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
        return [
            'country' => ['required', 'string', Rule::in(HolidayCatalog::Countries)],
            'region' => ['sometimes', 'nullable', 'string', 'max:8'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->filled('country') && $this->filled('region') && ! app(HolidayCatalog::class)->supports((string) $this->input('country'), (string) $this->input('region'))) {
                $validator->errors()->add('region', 'Unknown canton or region for the country.');
            }
        }];
    }
}
