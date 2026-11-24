<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSalesTeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'sales_representative_ids' => ['sometimes', 'array'],
            'sales_representative_ids.*' => ['uuid', SaveSalesTargetRequest::existsInTenant('sales_representatives')],
        ];
    }
}
