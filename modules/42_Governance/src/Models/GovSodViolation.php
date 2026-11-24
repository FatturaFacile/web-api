<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member who holds two conflicting duties. Open until the conflict disappears (resolved) or an exception covers it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rule_id
 * @property string $user_id
 * @property string $duty_a
 * @property string $duty_b
 * @property string $status
 * @property Carbon $detected_at
 * @property Carbon|null $resolved_at
 * @property string|null $exception_id
 */
final class GovSodViolation extends BaseEnterpriseModel
{
    public const array Statuses = ['open', 'excepted', 'resolved'];

    protected $table = 'gov_sod_violations';

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<GovSodRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(GovSodRule::class, 'rule_id');
    }
}
