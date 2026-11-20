<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A registered passkey. `sign_count` only grows: a lower or equal counter on login means a cloned authenticator.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id
 * @property string $public_key
 * @property int $sign_count
 * @property string|null $aaguid
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
final class WebAuthnCredential extends Model
{
    use BelongsToTenant, HasUuids, TracksAuditUsers;

    protected $table = 'webauthn_credentials';

    protected $fillable = ['tenant_id', 'user_id', 'name', 'credential_id', 'public_key', 'sign_count', 'aaguid', 'last_used_at', 'revoked_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Registers a successful assertion; false (and no change) when the counter regressed, i.e. a cloned key. */
    public function registerAssertion(int $signCount): bool
    {
        if ($signCount !== 0 || $this->sign_count !== 0) {
            if ($signCount <= $this->sign_count) {
                return false;
            }
        }

        $this->forceFill(['sign_count' => $signCount, 'last_used_at' => now()])->save();

        return true;
    }
}
