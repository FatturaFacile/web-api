<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The billing plan run: every pending item due on or before a date.
 */
final class RunBillingPlansRequest extends FormRequest
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
            'up_to_date' => ['required', 'date'],
        ];
    }
}
