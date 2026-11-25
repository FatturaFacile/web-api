<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A simap.ch publication payload to turn into a tender notice.
 */
final class IngestSimapRequest extends FormRequest
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
        return ['payload' => ['required', 'array']];
    }
}
