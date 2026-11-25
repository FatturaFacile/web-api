<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;

final class ListDossiersRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(DossierStatus::values())],
            'fiscal_year_id' => ['sometimes', 'uuid'],
            'standard' => ['sometimes', Rule::in(AccountingStandard::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'locale' => ['sometimes', Rule::in(['it', 'en', 'de', 'fr'])],
        ];
    }
}
