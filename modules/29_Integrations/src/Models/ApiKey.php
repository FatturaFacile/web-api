<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(ApiKeyFactory::class)]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const string TokenPrefix = 'ffk_';

    protected $fillable = ['tenant_id', 'name', 'prefix', 'key_hash', 'scopes', 'expires_at', 'last_used_at', 'revoked_at', 'created_by'];

    /**
     * @var list<string>
     */
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $table = $this->getTable();

        return [
            'status' => "CASE WHEN $table.revoked_at IS NOT NULL THEN 'revoked' ELSE 'active' END",
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
