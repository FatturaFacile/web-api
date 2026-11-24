<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answer given when a request is completed or rejected.
 */
final class OutcomeRequest extends FormRequest
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
        return ['outcome' => ['required', 'string', 'max:10000']];
    }
}
