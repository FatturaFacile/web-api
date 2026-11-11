<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Enasarco rate and ceiling of a year and agent type, with the source they were taken from. A null tenant is a generic
 * row, shown to every tenant and read-only for them; a row of the tenant takes precedence over it.
 */
final class EnasarcoParameter extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasUuids;

    public const array AGENT_TYPES = ['single_mandate', 'multi_mandate'];

    protected $fillable = ['tenant_id', 'year', 'agent_type', 'rate_percent', 'agent_share_percent', 'annual_ceiling', 'source'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'rate_percent' => 'decimal:2',
            'agent_share_percent' => 'decimal:2',
            'annual_ceiling' => 'decimal:2',
        ];
    }

    public static function resolve(string $tenantId, int $year, string $agentType): ?self
    {
        return self::withoutGlobalScopes()
            ->where('year', $year)
            ->where('agent_type', $agentType)
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL')
            ->first();
    }
}
