<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\WorkspaceSync\Contracts\WorkspaceProvider;
use Modules\WorkspaceSync\DTOs\OAuthTokens;
use Modules\WorkspaceSync\DTOs\SyncedItemData;

final readonly class MicrosoftGraphProvider implements WorkspaceProvider
{
    private const string AuthorityUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0';

    private const array Scopes = ['calendar' => 'Calendars.Read', 'mail' => 'Mail.ReadBasic'];

    public function __construct(private string $clientId, private string $clientSecret) {}

    public function name(): string
    {
        return 'microsoft';
    }

    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string
    {
        return self::AuthorityUrl.'/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'state' => $state,
            'scope' => implode(' ', ['offline_access', ...array_map(static fn (string $scope): string => self::Scopes[$scope], $scopes)]),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        return $this->tokens(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        return $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function calendarEvents(string $accessToken, CarbonImmutable $since): array
    {
        $body = Http::withToken($accessToken)->timeout(30)
            ->get('https://graph.microsoft.com/v1.0/me/events', [
                '$select' => 'id,subject,organizer,start,end',
                '$filter' => "start/dateTime ge '{$since->utc()->format('Y-m-d\TH:i:s')}'",
                '$top' => 100,
            ])
            ->throw()
            ->json();

        return array_map(static fn (array $event): SyncedItemData => new SyncedItemData(
            'calendar_event',
            (string) $event['id'],
            $event['subject'] ?? null,
            $event['organizer']['emailAddress']['address'] ?? null,
            $event['start']['dateTime'] ?? null,
            $event['end']['dateTime'] ?? null,
        ), $body['value'] ?? []);
    }

    public function messages(string $accessToken, CarbonImmutable $since): array
    {
        $body = Http::withToken($accessToken)->timeout(30)
            ->get('https://graph.microsoft.com/v1.0/me/messages', [
                '$select' => 'id,subject,from,receivedDateTime',
                '$filter' => "receivedDateTime ge {$since->utc()->format('Y-m-d\TH:i:s\Z')}",
                '$top' => 50,
            ])
            ->throw()
            ->json();

        return array_map(static fn (array $message): SyncedItemData => new SyncedItemData(
            'email',
            (string) $message['id'],
            $message['subject'] ?? null,
            $message['from']['emailAddress']['address'] ?? null,
            $message['receivedDateTime'] ?? null,
        ), $body['value'] ?? []);
    }

    /**
     * @param  array<string, string>  $grant
     */
    private function tokens(array $grant): OAuthTokens
    {
        $body = Http::asForm()->timeout(30)
            ->post(self::AuthorityUrl.'/token', [...$grant, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret])
            ->throw()
            ->json();

        return new OAuthTokens((string) $body['access_token'], $body['refresh_token'] ?? null, CarbonImmutable::now()->addSeconds((int) ($body['expires_in'] ?? 3600)));
    }
}
