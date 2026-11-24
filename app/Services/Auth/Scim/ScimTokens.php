<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use App\Models\ScimToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The SCIM bearer tokens of the tenants. A token is `scim_` plus 48 random characters (about 285 bits); only its SHA-256
 * is stored, so a database leak does not give access, and the plain value is returned once, when it is created.
 * A tenant has at most one usable token: rotating revokes the previous one in the same transaction.
 */
final class ScimTokens
{
    /**
     * Revokes the usable tokens of the tenant and creates a new one.
     *
     * @return array{0: ScimToken, 1: string} the stored token and its plain value
     */
    public function rotate(string $tenantId, ?string $createdBy, ?int $expiresInDays): array
    {
        $plain = 'scim_'.Str::random(48);

        $token = DB::transaction(function () use ($tenantId, $createdBy, $expiresInDays, $plain): ScimToken {
            ScimToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $token = new ScimToken([
                'token_hash' => $this->hash($plain),
                'token_hint' => substr($plain, -4),
                'created_by' => $createdBy,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
            ]);
            $token->forceFill(['tenant_id' => $tenantId])->save();

            return $token;
        });

        return [$token, $plain];
    }

    /**
     * @return int how many tokens were revoked
     */
    public function revoke(string $tenantId): int
    {
        return ScimToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * The usable token of the tenant, if any.
     */
    public function current(string $tenantId): ?ScimToken
    {
        return ScimToken::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('created_at')->first();
    }

    /**
     * The usable token that matches a plain value, from any tenant: the token itself identifies the tenant.
     */
    public function find(string $plain): ?ScimToken
    {
        /** @var ScimToken|null $token */
        $token = ScimToken::withoutGlobalScopes()->where('token_hash', $this->hash($plain))->first();

        return $token !== null && $token->isUsable() ? $token : null;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
