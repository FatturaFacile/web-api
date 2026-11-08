<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The month whose working time records are closed.
 */
final class CloseTimePeriodRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
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
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
