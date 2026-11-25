<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProvisionChartTemplateRequest extends FormRequest
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
            'include_default_mappings' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'in:it,en,de,fr'],
        ];
    }
}
