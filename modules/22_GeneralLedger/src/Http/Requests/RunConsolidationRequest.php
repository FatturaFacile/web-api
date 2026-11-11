<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fiscal period of the parent entity (the active tenant) a consolidation is run or reconciled for.
 */
final class RunConsolidationRequest extends FormRequest
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

        return [
            'fiscal_period_id' => ['required', 'uuid', Rule::exists('fiscal_periods', 'id')->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
        ];
    }

    /**
     * The reconciliation report is a GET: the period comes from the query string.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->isMethod('GET') ? $this->query() : $this->all();
    }
}
