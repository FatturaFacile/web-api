<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PatchStagingRowRequest extends FormRequest
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
            'values' => ['required', 'array', 'min:1'],
        ];
    }
}
