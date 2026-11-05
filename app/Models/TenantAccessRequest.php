<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company a self-registered user asked to work on. It stays pending until a platform administrator confirms it,
 * moves the user to another tenant or rejects it: until then the user has no tenant and cannot sign in.
 */
class TenantAccessRequest extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'requested_company_name',
        'requested_tax_id',
        'requested_country',
        'status',
        'decided_by',
        'decided_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
