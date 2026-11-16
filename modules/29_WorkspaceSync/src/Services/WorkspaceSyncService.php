<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\WorkspaceSync\DTOs\SyncedItemData;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;

/**
 * Per-user connection and sync. A connection only exists after that user went through the consent of the provider, the
 * `state` ties the callback to the same user and tenant, and revoking wipes the tokens and what was synced.
 */
final readonly class WorkspaceSyncService
{
    private const int StateMinutes = 10;

    private const int FirstSyncDays = 30;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private WorkspaceProviderFactory $providers,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function authorizationUrl(string $provider, array $scopes, User $user): string
    {
        $gateway = $this->providers->make($provider);
        $state = Str::random(64);

        Cache::put($this->stateKey($state), [
            'user_id' => (string) $user->id,
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'provider' => $provider,
            'scopes' => $scopes,
        ], now()->addMinutes(self::StateMinutes));

        return $gateway->authorizationUrl($scopes, $state, (string) config('workspace_sync.redirect_uri'));
    }

    /**
     * @throws ValidationException when the state is unknown, expired, already used, or belongs to someone else
     */
    public function connect(string $provider, string $code, string $state, User $user): WorkspaceConnection
    {
        $pending = Cache::pull($this->stateKey($state));
        $tenantId = (string) $this->tenantContext->getTenantId();

        if (! is_array($pending) || $pending['user_id'] !== (string) $user->id || $pending['tenant_id'] !== $tenantId || $pending['provider'] !== $provider) {
            throw ValidationException::withMessages(['state' => __('workspace_sync_errors.authorization.invalid')]);
        }

        $tokens = $this->providers->make($provider)->exchangeCode($code, (string) config('workspace_sync.redirect_uri'));

        return WorkspaceConnection::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'provider' => $provider],
            [
                'scopes' => $pending['scopes'],
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_expires_at' => $tokens->expiresAt,
                'consent_given_at' => now(),
                'revoked_at' => null,
            ]
        );
    }

    /**
     * @return int number of items stored or refreshed
     */
    public function sync(WorkspaceConnection $connection): int
    {
        if (! $connection->isActive()) {
            return 0;
        }

        $provider = $this->providers->make($connection->provider);
        $accessToken = $this->validAccessToken($connection);
        $since = CarbonImmutable::parse($connection->last_synced_at ?? now()->subDays(self::FirstSyncDays));
        $items = [];

        if (in_array('calendar', $connection->scopes ?? [], true)) {
            $items = [...$items, ...$provider->calendarEvents($accessToken, $since)];
        }

        if (in_array('mail', $connection->scopes ?? [], true)) {
            $items = [...$items, ...$provider->messages($accessToken, $since)];
        }

        DB::transaction(function () use ($connection, $items): void {
            foreach ($items as $item) {
                $this->store($connection, $item);
            }

            $connection->update(['last_synced_at' => now()]);
        });

        return count($items);
    }

    /**
     * Revoke.
     */
    public function revoke(WorkspaceConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            WorkspaceSyncedItem::withoutGlobalScopes()->where('connection_id', $connection->id)->forceDelete();
            $connection->update(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'revoked_at' => now()]);
        });
    }

    private function validAccessToken(WorkspaceConnection $connection): string
    {
        if ($connection->token_expires_at !== null && $connection->token_expires_at->isFuture()) {
            return (string) $connection->access_token;
        }

        $tokens = $this->providers->make($connection->provider)->refresh((string) $connection->refresh_token);

        $connection->update([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
        ]);

        return $tokens->accessToken;
    }

    private function store(WorkspaceConnection $connection, SyncedItemData $item): void
    {
        WorkspaceSyncedItem::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $connection->tenant_id, 'connection_id' => $connection->id, 'kind' => $item->kind, 'external_id' => $item->externalId],
            [
                'subject' => $item->subject === null ? null : mb_substr($item->subject, 0, 500),
                'counterpart' => $item->counterpart === null ? null : mb_substr($item->counterpart, 0, 255),
                'starts_at' => $item->startsAt,
                'ends_at' => $item->endsAt,
            ]
        );
    }

    private function stateKey(string $state): string
    {
        return 'workspace-oauth:'.hash('sha256', $state);
    }
}
