<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Contracts\Jobs\ExpireSignatureRequestsJob;
use Modules\Contracts\Models\ContractSignatureEvent;
use Modules\Contracts\Models\ContractSignatureRequest;
use Modules\Contracts\Services\SignatureService;

uses(RefreshDatabase::class);

/**
 * Create a sent request with two sequential signers and return its id with the tokens in signer order.
 *
 * @return array{id: string, tokens: list<string>}
 */
function publicSigningSentRequest(string $contractId, ?string $expiresOn = null): array
{
    $base = '/api/v1/contract-authoring';
    $version = test()->postJson("{$base}/contracts/customer/{$contractId}/versions", ['body' => 'Final text to sign'])->assertCreated()->json('data');
    $payload = ['document_version_id' => $version['id'], 'signers' => [['name' => 'Ann', 'email' => 'ann@example.com'], ['name' => 'Bob', 'email' => 'bob@example.com']]];

    if ($expiresOn !== null) {
        $payload['expires_on'] = $expiresOn;
    }

    $request = test()->postJson("{$base}/contracts/customer/{$contractId}/signature-requests", $payload)->assertCreated()->json('data');
    $sent = test()->postJson("{$base}/signature-requests/{$request['id']}/send")->assertOk()->json('signing_tokens');
    $tokens = array_map(static fn (array $signer): string => $sent[$signer['id']], $request['signers']);

    return ['id' => $request['id'], 'tokens' => $tokens];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $user = User::create(['name' => 'accountant', 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->contractId = $this->postJson('/api/v1/contract-register', [
        'kind' => 'customer', 'counterparty_id' => $customer->id, 'contract_number' => 'C-'.Str::random(6), 'title' => 'Maintenance',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_value' => '1200.00',
    ])->assertCreated()->json('data.id');
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Forget the authenticated session so that a call is what an anonymous signer sends.
 */
function publicSigningAnonymous(): void
{
    app('auth')->forgetGuards();
    app(TenantContext::class)->clear();
    test()->withoutHeader('X-Tenant-Id');
    test()->withoutHeader('Authorization');
}

test('a signer without an account sees, signs and completes the request through the token link', function (): void {
    $sent = publicSigningSentRequest($this->contractId);
    [$annToken, $bobToken] = $sent['tokens'];
    publicSigningAnonymous();

    $this->getJson("/api/v1/public/contract-signing/{$annToken}")->assertOk()
        ->assertJsonPath('data.body', 'Final text to sign')->assertJsonPath('data.can_sign', true)->assertJsonPath('data.signer_name', 'Ann')->assertJsonPath('data.company_name', 'Alfa');
    $this->getJson("/api/v1/public/contract-signing/{$bobToken}")->assertOk()->assertJsonPath('data.can_sign', false)->assertJsonPath('data.waiting_for_previous', true);
    $this->postJson("/api/v1/public/contract-signing/{$bobToken}/sign")->assertUnprocessable()->assertJsonValidationErrors('token');

    $this->postJson("/api/v1/public/contract-signing/{$annToken}/sign")->assertOk()->assertJsonPath('data.signer_status', 'signed');
    $this->postJson("/api/v1/public/contract-signing/{$annToken}/sign")->assertUnprocessable();
    $this->postJson("/api/v1/public/contract-signing/{$bobToken}/sign")->assertOk()->assertJsonPath('data.status', 'completed');

    app(TenantContext::class)->setTenant($this->tenant);
    $events = ContractSignatureEvent::query()->where('event', 'signed')->get();
    expect($events)->toHaveCount(2)->and($events->first()->metadata)->toHaveKey('ip');
});

test('an unknown or empty token is a 404 and the token is stored only as a hash', function (): void {
    $sent = publicSigningSentRequest($this->contractId);
    publicSigningAnonymous();

    $this->getJson('/api/v1/public/contract-signing/'.Str::random(48))->assertNotFound();
    $this->postJson('/api/v1/public/contract-signing/'.Str::random(48).'/sign')->assertNotFound();
    $this->postJson('/api/v1/public/contract-signing/'.Str::random(48).'/decline', ['reason' => 'No'])->assertNotFound();

    $stored = DB::table('contract_signers')->pluck('token_hash')->all();
    expect($stored)->not->toContain($sent['tokens'][0])->and($stored)->toContain(hash('sha256', $sent['tokens'][0]));
});

test('a signer declines through the link with a reason and the request ends declined', function (): void {
    $sent = publicSigningSentRequest($this->contractId);
    publicSigningAnonymous();

    $this->postJson("/api/v1/public/contract-signing/{$sent['tokens'][0]}/decline", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/public/contract-signing/{$sent['tokens'][0]}/decline", ['reason' => 'Terms not agreed'])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->postJson("/api/v1/public/contract-signing/{$sent['tokens'][1]}/sign")->assertUnprocessable();
});

test('the public endpoints are rate limited', function (): void {
    publicSigningAnonymous();

    foreach (range(1, 30) as $attempt) {
        $this->getJson('/api/v1/public/contract-signing/'.Str::random(48))->assertNotFound();
    }

    $this->getJson('/api/v1/public/contract-signing/'.Str::random(48))->assertStatus(429);
});

test('validation messages of the signature flow are translated in every language', function (): void {
    $sent = publicSigningSentRequest($this->contractId);
    publicSigningAnonymous();
    app()->setLocale('it');

    $this->getJson('/api/v1/public/contract-signing/'.Str::random(48))->assertNotFound();
    $this->postJson("/api/v1/public/contract-signing/{$sent['tokens'][1]}/sign")->assertUnprocessable()
        ->assertJsonPath('errors.token.0', trans('contracts.signature.sign_in_order', [], 'it'));

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(trans('contracts.signature.sign_in_order', [], $locale))->not->toBe('contracts.signature.sign_in_order');
    }

    $keys = array_keys(Arr::dot(require base_path('lang/en/contracts.php')));

    foreach (['it', 'de', 'fr'] as $locale) {
        expect(array_keys(Arr::dot(require base_path("lang/{$locale}/contracts.php"))))->toBe($keys);
    }
});

test('the scheduled job expires sent requests past their expiry date and keeps the others', function (): void {
    $past = publicSigningSentRequest($this->contractId, now()->addDay()->toDateString());
    ContractSignatureRequest::query()->whereKey($past['id'])->update(['expires_on' => now()->subDays(2)->toDateString()]);
    $future = publicSigningSentRequest($this->contractId, now()->addDays(5)->toDateString());
    // The first version already has an expired request, a second version is needed for a second request.
    expect($future['id'])->not->toBe($past['id']);

    (new ExpireSignatureRequestsJob($this->tenant->id))->handle(app(SignatureService::class));

    expect(ContractSignatureRequest::query()->find($past['id'])->status)->toBe('expired')
        ->and(ContractSignatureRequest::query()->find($future['id'])->status)->toBe('sent')
        ->and(ContractSignatureEvent::query()->where('request_id', $past['id'])->where('event', 'expired')->count())->toBe(1);

    expect(app(SignatureService::class)->expireOverdue($this->tenant->id, CarbonImmutable::today()))->toBe(0);

    publicSigningAnonymous();
    $this->postJson("/api/v1/public/contract-signing/{$past['tokens'][0]}/sign")->assertUnprocessable();
});
