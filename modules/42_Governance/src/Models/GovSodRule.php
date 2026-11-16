<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A segregation of duties rule: nobody may hold a duty of group A together with a duty of group B. A `block` rule refuses
 * the assignment unless an exception is active, a `warn` rule allows it and records a violation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property list<string> $duties_a
 * @property list<string> $duties_b
 * @property string $severity
 * @property bool $is_active
 */
final class GovSodRule extends BaseEnterpriseModel
{
    public const array Severities = ['warn', 'block'];

    protected $table = 'gov_sod_rules';

    protected function casts(): array
    {
        return ['duties_a' => 'array', 'duties_b' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<GovSodViolation, $this>
     */
    public function violations(): HasMany
    {
        return $this->hasMany(GovSodViolation::class, 'rule_id');
    }
}
