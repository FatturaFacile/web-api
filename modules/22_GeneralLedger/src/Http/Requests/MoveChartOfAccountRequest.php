<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class MoveChartOfAccountRequest extends FormRequest
{
    use TenantScopedRules;

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
            'parent_account_id' => ['nullable', 'uuid', $this->tenantExists('chart_of_accounts')],
        ];
    }
}
