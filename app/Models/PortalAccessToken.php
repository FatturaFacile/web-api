<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PortalAccessToken extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'portal_access_tokens';

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'token_hash',
        'token_type',
        'scope_type',
        'scope_id',
        'expires_at',
        'last_accessed_at',
        'is_single_use',
        'used_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'is_single_use' => 'boolean',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Polymorphic target if token is scoped to a specific quote, invoice, or ticket.
     *
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PortalActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(PortalActivityLog::class, 'portal_access_token_id');
    }

    /**
     * Check if token is still valid.
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        if ($this->is_single_use && $this->used_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * Mark single-use token as used.
     */
    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }

    /**
     * Scope only valid, active tokens.
     *
     * @param  Builder<PortalAccessToken>  $query
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('is_single_use', false)->orWhereNull('used_at'));
    }
}
