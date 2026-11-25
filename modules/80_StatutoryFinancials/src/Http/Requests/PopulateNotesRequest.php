<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PopulateNotesRequest extends FormRequest
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
        return ['overwrite_narrative' => ['sometimes', 'boolean']];
    }
}
