<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Identity\Domain\ValueObjects\AbacCondition;
use Modules\Platform\Identity\Domain\ValueObjects\AbacRule;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $role_id
 * @property string $permission
 * @property string $effect
 * @property list<array{attribute: string, operator: string, value: mixed}> $conditions
 */
final class AbacRuleRecord extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasUuids, TracksAuditUsers;

    protected $table = 'abac_rules';

    protected $fillable = ['tenant_id', 'role_id', 'permission', 'effect', 'conditions'];

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    public function toDomain(): AbacRule
    {
        $conditions = array_map(
            static fn (array $c): AbacCondition => new AbacCondition($c['attribute'], $c['operator'], $c['value']),
            $this->conditions,
        );

        return new AbacRule($this->permission, $this->effect, $conditions);
    }
}
