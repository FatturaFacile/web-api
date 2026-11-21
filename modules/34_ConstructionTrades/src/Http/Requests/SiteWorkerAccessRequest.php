<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A worker asking for access to a construction site on a given day.
 */
final class SiteWorkerAccessRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'access_date' => ['nullable', 'date'],
        ];
    }
}
