<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\WorkspaceSync\Jobs\SyncWorkspaceConnectionsJob;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;
use Modules\WorkspaceSync\Services\WorkspaceSyncService;

uses(RefreshDatabase::class);

function workspaceMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function workspaceActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function fakeGoogle(): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/*' => Http::response(['items' => [
            ['id' => 'ev1', 'summary' => 'Budget meeting', 'organizer' => ['email' => 'boss@example.com'], 'start' => ['dateTime' => '2026-11-10T09:00:00+01:00'], 'end' => ['dateTime' => '2026-11-10T10:00:00+01:00']],
        ]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
            'internalDate' => '1794000000000',
            'payload' => ['headers' => [['name' => 'Subject', 'value' => 'Offerta'], ['name' => 'From', 'value' => 'cliente@example.com']]],
        ]),
    ]);
}

function connectGoogle(array $scopes = ['calendar', 'mail']): string
{
    $url = test()->postJson('/api/v1/workspace/connections/google/authorize', ['consent' => true, 'scopes' => $scopes])->assertOk()->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return test()->postJson('/api/v1/workspace/connections/google/callback', ['code' => 'abc', 'state' => $query['state']])->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    config([
        'workspace_sync.redirect_uri' => 'https://app.example.com/oauth',
        'workspace_sync.providers.google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret'],
        'workspace_sync.providers.microsoft' => ['client_id' => 'm-id', 'client_secret' => 'm-secret'],
    ]);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->user = workspaceMember($this->tenant);
    workspaceActAs($this->user, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('without consent no connection can start', function (): void {
    $this->postJson('/api/v1/workspace/connections/google/authorize', ['consent' => false, 'scopes' => ['calendar']])->assertUnprocessable();
    $this->postJson('/api/v1/workspace/connections/google/authorize', ['scopes' => ['calendar']])->assertUnprocessable();
});

test('a provider without credentials refuses to connect', function (): void {
    config(['workspace_sync.providers.microsoft' => ['client_id' => null, 'client_secret' => null]]);

    $this->postJson('/api/v1/workspace/connections/microsoft/authorize', ['consent' => true, 'scopes' => ['mail']])->assertServiceUnavailable();
});

test('the authorization address asks only read-only scopes', function (): void {
    $url = $this->postJson('/api/v1/workspace/connections/google/authorize', ['consent' => true, 'scopes' => ['calendar', 'mail']])->json('data.authorization_url');

    expect($url)->toContain('calendar.readonly')->toContain('gmail.metadata')->not->toContain('gmail.modify');

    $microsoft = $this->postJson('/api/v1/workspace/connections/microsoft/authorize', ['consent' => true, 'scopes' => ['mail']])->json('data.authorization_url');

    expect($microsoft)->toContain('Mail.ReadBasic')->not->toContain('Mail.ReadWrite');
});

test('tokens are stored encrypted and never returned', function (): void {
    fakeGoogle();
    $id = connectGoogle();

    $raw = DB::table('workspace_connections')->where('id', $id)->first();
    $connection = WorkspaceConnection::query()->findOrFail($id);

    expect($raw->access_token)->not->toContain('google-access')
        ->and($raw->refresh_token)->not->toContain('google-refresh')
        ->and($connection->access_token)->toBe('google-access')
        ->and($connection->toArray())->not->toHaveKey('access_token');

    $this->getJson('/api/v1/workspace/connections')->assertOk()->assertJsonMissingPath('data.0.access_token');
});

test('a state works once, and only for the user who started', function (): void {
    fakeGoogle();
    $url = $this->postJson('/api/v1/workspace/connections/google/authorize', ['consent' => true, 'scopes' => ['mail']])->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    workspaceActAs(workspaceMember($this->tenant), $this->tenant);
    $this->postJson('/api/v1/workspace/connections/google/callback', ['code' => 'abc', 'state' => $query['state']])->assertUnprocessable();

    workspaceActAs($this->user, $this->tenant);
    $this->postJson('/api/v1/workspace/connections/google/callback', ['code' => 'abc', 'state' => $query['state']])->assertUnprocessable();
    $this->postJson('/api/v1/workspace/connections/google/callback', ['code' => 'abc', 'state' => 'forged'])->assertUnprocessable();

    expect(WorkspaceConnection::query()->count())->toBe(0);
});

test('google sync stores calendar events and message metadata without bodies', function (): void {
    fakeGoogle();
    $id = connectGoogle();

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk()->assertJsonPath('data.items', 2);

    $items = collect($this->getJson('/api/v1/workspace/items')->assertOk()->json('data'))->keyBy('kind');

    expect($items['calendar_event']['subject'])->toBe('Budget meeting')
        ->and($items['email']['subject'])->toBe('Offerta')
        ->and($items['email']['counterpart'])->toBe('cliente@example.com');

    Http::assertSent(fn (HttpRequest $request): bool => ! str_contains($request->url(), 'format=full') && ! str_contains($request->url(), 'format=raw'));

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();
    expect(WorkspaceSyncedItem::query()->count())->toBe(2);
});

test('a scope that was not granted is not read', function (): void {
    fakeGoogle();
    $id = connectGoogle(['calendar']);

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk()->assertJsonPath('data.items', 1);

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'gmail'));
});

test('microsoft sync reads Graph with the token of the user', function (): void {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'ms-access', 'refresh_token' => 'ms-refresh', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/me/events*' => Http::response(['value' => [['id' => 'e1', 'subject' => 'Review', 'organizer' => ['emailAddress' => ['address' => 'a@example.com']], 'start' => ['dateTime' => '2026-11-10T09:00:00'], 'end' => ['dateTime' => '2026-11-10T10:00:00']]]]),
        'graph.microsoft.com/v1.0/me/messages*' => Http::response(['value' => [['id' => 'x1', 'subject' => 'Hello', 'from' => ['emailAddress' => ['address' => 'b@example.com']], 'receivedDateTime' => '2026-11-07T08:00:00Z']]]),
    ]);

    $url = $this->postJson('/api/v1/workspace/connections/microsoft/authorize', ['consent' => true, 'scopes' => ['calendar', 'mail']])->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $id = $this->postJson('/api/v1/workspace/connections/microsoft/callback', ['code' => 'abc', 'state' => $query['state']])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk()->assertJsonPath('data.items', 2);

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'graph.microsoft.com') && $request->hasHeader('Authorization', 'Bearer ms-access'));
});

test('an expired token is refreshed before the sync', function (): void {
    fakeGoogle();
    $id = connectGoogle(['calendar']);
    WorkspaceConnection::query()->findOrFail($id)->update(['token_expires_at' => now()->subMinute()]);

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token') && $request['grant_type'] === 'refresh_token');
    expect(WorkspaceConnection::query()->findOrFail($id)->token_expires_at->isFuture())->toBeTrue();
});

test('revoking erases tokens and synced items, and the job skips it', function (): void {
    fakeGoogle();
    $id = connectGoogle();
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();

    $this->deleteJson("/api/v1/workspace/connections/{$id}")->assertOk();

    $raw = DB::table('workspace_connections')->where('id', $id)->first();

    expect($raw->access_token)->toBeNull()
        ->and($raw->refresh_token)->toBeNull()
        ->and($raw->revoked_at)->not->toBeNull()
        ->and(DB::table('workspace_synced_items')->count())->toBe(0);

    Http::fake();
    app(TenantContext::class)->clear();
    (new SyncWorkspaceConnectionsJob((string) $this->tenant->id))->handle(app(WorkspaceSyncService::class));
    Http::assertNothingSent();
});

test('the daily job syncs the active connections of its tenant and survives a failure', function (): void {
    fakeGoogle();
    connectGoogle(['calendar']);

    app(TenantContext::class)->clear();
    (new SyncWorkspaceConnectionsJob((string) $this->tenant->id))->handle(app(WorkspaceSyncService::class));

    expect(WorkspaceSyncedItem::withoutGlobalScopes()->count())->toBe(1);

    Http::fake(['*' => Http::response([], 500)]);
    WorkspaceConnection::withoutGlobalScopes()->update(['last_synced_at' => null]);
    (new SyncWorkspaceConnectionsJob((string) $this->tenant->id))->handle(app(WorkspaceSyncService::class));
});

test('nobody reaches the connection of a colleague, not even an owner', function (): void {
    fakeGoogle();
    $id = connectGoogle();
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();

    workspaceActAs(workspaceMember($this->tenant, 'owner'), $this->tenant);

    $this->getJson('/api/v1/workspace/connections')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/workspace/items')->assertOk()->assertJsonPath('data', []);
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertNotFound();
    $this->deleteJson("/api/v1/workspace/connections/{$id}")->assertNotFound();
});

test('another tenant sees nothing', function (): void {
    fakeGoogle();
    $id = connectGoogle();

    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->user->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    workspaceActAs($this->user, $other);

    $this->getJson('/api/v1/workspace/connections')->assertOk()->assertJsonPath('data', []);
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertNotFound();
});

test('a failed code exchange answers 502 and stores nothing', function (): void {
    $url = $this->postJson('/api/v1/workspace/connections/google/authorize', ['consent' => true, 'scopes' => ['calendar']])->assertOk()->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    Http::fake(['*' => Http::response([], 500)]);
    $this->postJson('/api/v1/workspace/connections/google/callback', ['code' => 'abc', 'state' => $query['state']])->assertStatus(502);
    expect(WorkspaceConnection::query()->count())->toBe(0);
});

test('a provider refusing the refresh during a sync answers 502', function (): void {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_in' => 3600])
            ->push([], 500),
    ]);
    $id = connectGoogle(['calendar']);
    WorkspaceConnection::query()->findOrFail($id)->update(['token_expires_at' => now()->subMinute()]);

    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertStatus(502);
});

test('items are narrowed to the exchanges with a CRM contact and paginated', function (): void {
    fakeGoogle();
    $id = connectGoogle();
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();
    $client = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente', 'email' => 'CLIENTE@example.com']);
    $stranger = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Altro', 'email' => 'altro@example.com']);

    $items = $this->getJson("/api/v1/workspace/items?contact_id={$client->id}")->assertOk()->assertJsonPath('meta.total', 1)->json('data');
    expect($items[0]['kind'])->toBe('email')->and($items[0]['subject'])->toBe('Offerta');
    $this->getJson("/api/v1/workspace/items?contact_id={$stranger->id}")->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/workspace/items?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/workspace/items?kind=sms')->assertUnprocessable()->assertJsonValidationErrors('kind');
});

test('the contact filter refuses a contact of another tenant and a colleague never sees these items', function (): void {
    fakeGoogle();
    $id = connectGoogle();
    $this->postJson("/api/v1/workspace/connections/{$id}/sync")->assertOk();
    $foreign = Contact::create(['tenant_id' => Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA'])->id, 'type' => 'customer', 'company_name' => 'X', 'email' => 'cliente@example.com']);

    $this->getJson("/api/v1/workspace/items?contact_id={$foreign->id}")->assertUnprocessable()->assertJsonValidationErrors('contact_id');

    workspaceActAs(workspaceMember($this->tenant), $this->tenant);
    $this->getJson('/api/v1/workspace/items')->assertOk()->assertJsonPath('meta.total', 0);
});
