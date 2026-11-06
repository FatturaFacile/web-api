<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation pieces shared by the customs requests.
 */
final class CustomsRules
{
    /**
     * The row must belong to the active company or be generic.
     */
    public static function exists(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }

    /**
     * @return list<string>
     */
    public static function country(): array
    {
        return ['string', 'size:2', 'regex:/^[A-Z]{2}$/'];
    }

    /**
     * @return list<string>
     */
    public static function direction(): array
    {
        return ['string', 'in:import,export,transit'];
    }
}
