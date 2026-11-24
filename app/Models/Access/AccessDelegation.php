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
 * For a period the delegate acts with the role of the delegator (`role`, the role the delegator held when the delegation
 * was given), for every module or only for `modules`.
 *
 * @property string $id
 * @property string $delegator_id
 * @property string $delegate_id
 * @property string $role
 * @property list<string>|null $modules
 * @property string|null $reason
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $revoked_at
 */
class AccessDelegation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'access_delegations';

    protected $fillable = ['tenant_id', 'delegator_id', 'delegate_id', 'role', 'modules', 'reason', 'starts_at', 'ends_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->ends_at->isPast() => 'expired',
            $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
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
}
