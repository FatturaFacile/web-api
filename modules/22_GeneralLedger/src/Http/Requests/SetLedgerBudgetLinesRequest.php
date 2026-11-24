<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetLedgerBudgetLinesRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();
        $inTenant = static fn (string $table) => Rule::exists($table, 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        return [
            'lines' => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.account_id' => ['required', 'uuid', $inTenant('chart_of_accounts')],
            'lines.*.cost_center_id' => ['nullable', 'uuid', $inTenant('cost_centers')],
            'lines.*.period_number' => ['required', 'integer', 'min:1', 'max:12'],
            'lines.*.amount' => ['required', 'decimal:0,4'],
        ];
    }
}
