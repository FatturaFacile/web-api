<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A member lends their role to a colleague for a time window (holidays, absence). `modules` limits the lent access
 * to some modules; null lends every module of the role.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $delegator_id
 * @property string $delegate_id
 * @property string $role
 * @property list<string>|null $modules
 * @property string|null $reason
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $revoked_at
 */
class TenantAccessDelegation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'tenant_access_delegations';

    protected $fillable = ['tenant_id', 'delegator_id', 'delegate_id', 'role', 'modules', 'reason', 'starts_at', 'ends_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
