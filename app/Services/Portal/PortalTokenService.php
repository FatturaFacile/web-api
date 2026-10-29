<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Models\PortalActivityLog;
use App\Services\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PortalTokenService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Generate and store a secure portal magic link token.
     *
     * @return array{token: string, model: PortalAccessToken}
     */
    public function createToken(
        Contact $contact,
        string $tokenType = 'dashboard',
        ?Model $scope = null,
        ?DateTimeInterface $expiresAt = null,
        bool $isSingleUse = false
    ): array {
        $plaintext = bin2hex(random_bytes(32)); // 64 hex characters (256-bit entropy)
        $tokenHash = hash('sha256', $plaintext);

        $expiresAt ??= now()->addDays(30);

        /** @var PortalAccessToken $model */
        $model = PortalAccessToken::create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'scope_type' => $scope ? $scope::class : null,
            'scope_id' => $scope?->getKey(),
            'expires_at' => $expiresAt,
            'is_single_use' => $isSingleUse,
        ]);

        return [
            'token' => $plaintext,
            'model' => $model,
        ];
    }

    /**
     * Resolve and validate a plaintext token from the URL.
     * Sets tenant context automatically upon successful resolution.
     */
    public function resolveToken(string $plaintext): PortalAccessToken
    {
        $tokenHash = hash('sha256', trim($plaintext));

        /** @var PortalAccessToken|null $token */
        $token = PortalAccessToken::withoutGlobalScopes()
            ->with(['contact', 'tenant'])
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $token) {
            throw new NotFoundHttpException('Invalid or expired portal link.');
        }

        if (! $token->isValid()) {
            throw new HttpException(410, 'This portal access link has expired or was already used.');
        }

        // Bind tenant context for multi-tenant scopes
        $this->tenantContext->setTenant($token->tenant);

        // Update last accessed
        $token->update(['last_accessed_at' => now()]);

        return $token;
    }

    /**
     * Record a secure audit log for customer portal interactions.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logActivity(
        PortalAccessToken $token,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $metadata = []
    ): PortalActivityLog {
        return PortalActivityLog::create([
            'tenant_id' => $token->tenant_id,
            'contact_id' => $token->contact_id,
            'portal_access_token_id' => $token->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
