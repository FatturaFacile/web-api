<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\WorkspaceSync\Contracts\WorkspaceProvider;
use Modules\WorkspaceSync\DTOs\OAuthTokens;
use Modules\WorkspaceSync\DTOs\SyncedItemData;

final readonly class GoogleWorkspaceProvider implements WorkspaceProvider
{
    private const string TokenUrl = 'https://oauth2.googleapis.com/token';

    private const array Scopes = [
        'calendar' => 'https://www.googleapis.com/auth/calendar.readonly',
        'mail' => 'https://www.googleapis.com/auth/gmail.metadata',
    ];

    public function __construct(private string $clientId, private string $clientSecret) {}

    public function name(): string
    {
        return 'google';
    }

    public function authorizationUrl(array $scopes, string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'scope' => implode(' ', array_map(static fn (string $scope): string => self::Scopes[$scope], $scopes)),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        return $this->tokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        return $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function calendarEvents(string $accessToken, CarbonImmutable $since): array
    {
        $body = Http::withToken($accessToken)->timeout(30)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin' => $since->toRfc3339String(),
                'singleEvents' => 'true',
                'maxResults' => 250,
                'fields' => 'items(id,summary,organizer(email),start,end)',
            ])
            ->throw()
            ->json();

        return array_map(static fn (array $event): SyncedItemData => new SyncedItemData(
            'calendar_event',
            (string) $event['id'],
            $event['summary'] ?? null,
            $event['organizer']['email'] ?? null,
            $event['start']['dateTime'] ?? $event['start']['date'] ?? null,
            $event['end']['dateTime'] ?? $event['end']['date'] ?? null,
        ), $body['items'] ?? []);
    }

    public function messages(string $accessToken, CarbonImmutable $since): array
    {
        $list = Http::withToken($accessToken)->timeout(30)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', ['maxResults' => 50, 'q' => 'after:'.$since->getTimestamp()])
            ->throw()
            ->json();

        $items = [];

        foreach ($list['messages'] ?? [] as $message) {
            $detail = Http::withToken($accessToken)->timeout(30)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$message['id']}", 'format=metadata&metadataHeaders=Subject&metadataHeaders=From')
                ->throw()
                ->json();

            $headers = collect($detail['payload']['headers'] ?? [])->pluck('value', 'name');
            $items[] = new SyncedItemData(
                'email',
                (string) $message['id'],
                $headers->get('Subject'),
                $headers->get('From'),
                isset($detail['internalDate']) ? CarbonImmutable::createFromTimestampMs((int) $detail['internalDate'])->toIso8601String() : null,
            );
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $grant
     */
    private function tokens(array $grant): OAuthTokens
    {
        $body = Http::asForm()->timeout(30)
            ->post(self::TokenUrl, [...$grant, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret])
            ->throw()
            ->json();

        return new OAuthTokens((string) $body['access_token'], $body['refresh_token'] ?? null, CarbonImmutable::now()->addSeconds((int) ($body['expires_in'] ?? 3600)));
    }
}
