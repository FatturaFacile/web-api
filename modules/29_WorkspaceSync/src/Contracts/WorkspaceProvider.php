<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Modules\WorkspaceSync\DTOs\OAuthTokens;
use Modules\WorkspaceSync\DTOs\SyncedItemData;

/**
 * Calendar and mail of one user at a provider, read-only.
 */
interface WorkspaceProvider
{
    public function name(): string;

    /**
     * @param  list<string>  $scopes  calendar, mail
     */
    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string;

    /**
     * @throws RequestException
     */
    public function exchangeCode(string $code, string $redirectUri): OAuthTokens;

    /**
     * @throws RequestException
     */
    public function refresh(string $refreshToken): OAuthTokens;

    /**
     * @return list<SyncedItemData>
     *
     * @throws RequestException
     */
    public function calendarEvents(string $accessToken, CarbonImmutable $since): array;

    /**
     * Metadata only: message bodies are never requested.
     *
     * @return list<SyncedItemData>
     *
     * @throws RequestException
     */
    public function messages(string $accessToken, CarbonImmutable $since): array;
}
