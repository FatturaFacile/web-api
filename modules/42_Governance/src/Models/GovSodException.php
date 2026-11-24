<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A time-boxed permission for one member to hold the conflicting duties of a rule, approved by someone else.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rule_id
 * @property string $user_id
 * @property string $justification
 * @property string $approved_by
 * @property Carbon $expires_at
 * @property string $status
 * @property Carbon|null $revoked_at
 */
final class GovSodException extends BaseEnterpriseModel
{
    protected $table = 'gov_sod_exceptions';

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'revoked_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null && ! $this->expires_at->endOfDay()->isPast();
    }

    /**
     * @return BelongsTo<GovSodRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(GovSodRule::class, 'rule_id');
    }
}
