<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignInvoicesRequest extends FormRequest
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
            'assignments' => ['required', 'array', 'min:1', 'max:500'],
            'assignments.*.invoice_id' => ['required', 'uuid'],
            'assignments.*.delivery_point_id' => ['required', 'uuid'],
        ];
    }
}
