<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class SaveSalesTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An agent or a team, never both.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sales_representative_id' => ['required_without:sales_team_id', 'prohibits:sales_team_id', 'nullable', 'uuid', self::existsInTenant('sales_representatives')],
            'sales_team_id' => ['nullable', 'uuid', self::existsInTenant('sales_teams')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'target_amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }

    public static function existsInTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->whereNull('deleted_at');
    }
}
