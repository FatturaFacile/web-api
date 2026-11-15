<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Contracts\Models\ContractClause;
use Modules\Contracts\Models\ContractSignatureEvent;
use Modules\Contracts\Services\TextDiff;

uses(RefreshDatabase::class);

function authoringMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function authoringActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function authoringClause(string $code, string $body, bool $approve = true, array $overrides = []): string
{
    $id = test()->postJson('/api/v1/contract-authoring/clauses', ['code' => $code, 'title' => 'Clause '.$code, 'body' => $body, ...$overrides])->assertCreated()->json('data.id');

    if ($approve) {
        test()->postJson("/api/v1/contract-authoring/clauses/{$id}/status", ['status' => 'approved'])->assertOk();
    }

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    authoringActAs(authoringMember($this->tenant, 'accountant'), $this->tenant);
    $this->contractId = $this->postJson('/api/v1/contract-register', [
        'kind' => 'customer', 'counterparty_id' => $customer->id, 'contract_number' => 'C-'.Str::random(6), 'title' => 'Maintenance',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_value' => '1200.00',
    ])->assertCreated()->json('data.id');
    $this->base = '/api/v1/contract-authoring';
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a clause keeps every version, needs a version to be approved and a retired clause is final', function (): void {
    $id = authoringClause('CONF', 'First text', false);

    $this->postJson("{$this->base}/clauses", ['code' => 'CONF', 'title' => 'Dup', 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson("{$this->base}/clauses/{$id}/versions", ['body' => 'Second text', 'change_note' => 'Tightened'])->assertCreated()
        ->assertJsonPath('data.current_version_no', 2)->assertJsonPath('data.current_body', 'Second text')->assertJsonCount(2, 'data.versions');

    $this->postJson("{$this->base}/clauses/{$id}/fallbacks", ['title' => 'Softer', 'body' => 'Soft text'])->assertCreated()->assertJsonPath('data.fallbacks.0.rank', 1);
    $this->postJson("{$this->base}/clauses/{$id}/fallbacks", ['title' => 'Dup rank', 'body' => 'x', 'rank' => 1])->assertUnprocessable();

    $this->postJson("{$this->base}/clauses/{$id}/status", ['status' => 'retired'])->assertOk();
    $this->postJson("{$this->base}/clauses/{$id}/versions", ['body' => 'Third'])->assertUnprocessable();
    $this->postJson("{$this->base}/clauses/{$id}/status", ['status' => 'approved'])->assertUnprocessable();

    $empty = ContractClause::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->postJson("{$this->base}/clauses/{$empty->id}/status", ['status' => 'approved'])->assertUnprocessable();

    $this->getJson("{$this->base}/clauses?status=retired")->assertOk()->assertJsonCount(1, 'data');
});

test('clauses and templates of another company stay invisible', function (): void {
    $foreign = ContractClause::factory()->create(['tenant_id' => $this->other->id]);

    $this->getJson("{$this->base}/clauses/{$foreign->id}")->assertNotFound();
    $this->postJson("{$this->base}/templates", ['name' => 'T', 'clauses' => [['clause_id' => $foreign->id]]])->assertUnprocessable()->assertJsonValidationErrors('clauses.0.clause_id');
});

test('a template assembles approved clauses with variables, optional clauses and fallback wordings', function (): void {
    $intro = authoringClause('INTRO', 'Agreement between {{ supplier }} and {{customer}}.');
    $penalty = authoringClause('PEN', 'Penalty of {{penalty}} applies.');
    $this->postJson("{$this->base}/clauses/{$penalty}/fallbacks", ['title' => 'Cap', 'body' => 'Penalty is capped.'])->assertCreated();
    $draft = authoringClause('DRAFT', 'Not approved', false);

    $this->postJson("{$this->base}/templates", ['name' => 'Active with draft', 'status' => 'active', 'clauses' => [['clause_id' => $draft]]])->assertUnprocessable()->assertJsonValidationErrors('clauses');

    $template = $this->postJson("{$this->base}/templates", [
        'name' => 'Service', 'contract_kind' => 'customer', 'status' => 'active',
        'variables' => [['key' => 'supplier', 'required' => true], ['key' => 'customer', 'required' => true], ['key' => 'penalty', 'default' => '5%']],
        'clauses' => [['clause_id' => $intro], ['clause_id' => $penalty, 'is_optional' => true]],
    ])->assertCreated()->assertJsonCount(2, 'data.clauses')->json('data');
    $optionalEntry = $template['clauses'][1]['id'];

    $this->postJson("{$this->base}/templates/{$template['id']}/preview", ['variables' => ['supplier' => 'Alfa']])->assertUnprocessable()->assertJsonValidationErrors('variables');

    $preview = $this->postJson("{$this->base}/templates/{$template['id']}/preview", ['variables' => ['supplier' => 'Alfa', 'customer' => 'Beta']])->assertOk()->json('data.body');
    expect($preview)->toContain('Agreement between Alfa and Beta.')->not->toContain('Penalty');

    $withOptional = $this->postJson("{$this->base}/templates/{$template['id']}/preview", ['variables' => ['supplier' => 'Alfa', 'customer' => 'Beta'], 'include_optional' => [$optionalEntry]])->assertOk()->json('data.body');
    expect($withOptional)->toContain('Penalty of 5% applies.');

    $fallback = $this->postJson("{$this->base}/templates/{$template['id']}/preview", ['variables' => ['supplier' => 'Alfa', 'customer' => 'Beta'], 'include_optional' => [$optionalEntry], 'fallback_ranks' => [$penalty => 1]])->assertOk()->json('data.body');
    expect($fallback)->toContain('Penalty is capped.');

    $version = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions/from-template", ['template_id' => $template['id'], 'variables' => ['supplier' => 'Alfa', 'customer' => 'Beta']])->assertCreated()
        ->assertJsonPath('data.version_no', 1)->assertJsonPath('data.source', 'template')->json('data');
    expect($version['body_hash'])->toBe(hash('sha256', $version['body']));

    $this->postJson("{$this->base}/contracts/supplier/{$this->contractId}/versions/from-template", ['template_id' => $template['id'], 'variables' => ['supplier' => 'A', 'customer' => 'B']])->assertUnprocessable();
});

test('redlining numbers versions, diffs them, keeps comments and runs one negotiation round at a time', function (): void {
    $v1 = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions", ['body' => "One\nTwo\nThree"])->assertCreated()->json('data');
    $round = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/rounds", ['summary' => 'Sent to the customer'])->assertCreated()->assertJsonPath('data.round_no', 1)->json('data');
    $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/rounds")->assertUnprocessable();

    $v2 = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions", ['body' => "One\nTwo changed\nThree\nFour", 'source' => 'counterparty'])->assertCreated()
        ->assertJsonPath('data.version_no', 2)->assertJsonPath('data.round_id', $round['id'])->json('data');
    expect($v1['round_id'])->toBeNull();

    $diff = $this->getJson("{$this->base}/contracts/customer/{$this->contractId}/versions/compare?from=1&to=2")->assertOk()->assertJsonPath('data.added', 2)->assertJsonPath('data.removed', 1)->json('data.lines');
    expect(array_column($diff, 'type'))->toBe(['equal', 'removed', 'added', 'equal', 'added']);
    $this->getJson("{$this->base}/contracts/customer/{$this->contractId}/versions/compare?from=1&to=9")->assertNotFound();

    $comment = $this->postJson("{$this->base}/document-versions/{$v2['id']}/comments", ['body' => 'Why changed?', 'line_number' => 2])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
    $this->getJson("{$this->base}/contracts/customer/{$this->contractId}/versions")->assertOk()->assertJsonPath('data.0.open_comments', 1);
    $this->postJson("{$this->base}/contract-comments/{$comment['id']}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson("{$this->base}/contract-comments/{$comment['id']}/resolve")->assertUnprocessable();

    $this->postJson("{$this->base}/negotiation-rounds/{$round['id']}/close", ['outcome' => 'agreed'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("{$this->base}/negotiation-rounds/{$round['id']}/close", ['outcome' => 'agreed'])->assertUnprocessable();
    $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/rounds")->assertCreated()->assertJsonPath('data.round_no', 2);
});

test('the text diff handles empty texts', function (): void {
    $diff = new TextDiff;

    expect($diff->lines('', ''))->toBe([])
        ->and($diff->lines('', "a\nb"))->toHaveCount(2)
        ->and(array_column($diff->lines("a\nb", ''), 'type'))->toBe(['removed', 'removed']);
});

test('a sequential signature request follows the order, logs every step and completes with the last signer', function (): void {
    $version = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions", ['body' => 'Final text'])->assertCreated()->json('data');
    $payload = ['document_version_id' => $version['id'], 'signers' => [['name' => 'Ann', 'email' => 'ann@example.com'], ['name' => 'Bob', 'email' => 'bob@example.com']]];

    $request = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.document_hash', $version['body_hash'])->json('data');
    $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", $payload)->assertUnprocessable();
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => 'whatever'])->assertUnprocessable();

    $sent = $this->postJson("{$this->base}/signature-requests/{$request['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent')->json();
    $tokens = $sent['signing_tokens'];
    [$ann, $bob] = array_column($request['signers'], 'id');
    expect($tokens)->toHaveCount(2)->and($sent['data']['signers'][0])->not->toHaveKey('token_hash');
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/send")->assertUnprocessable();

    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => $tokens[$bob]])->assertUnprocessable();
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => Str::random(48)])->assertUnprocessable();
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => $tokens[$ann]])->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => $tokens[$ann]])->assertUnprocessable();
    $done = $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => $tokens[$bob]])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
    expect($done['signers'][1]['signature_hash'])->toHaveLength(64);

    $events = $this->getJson("{$this->base}/signature-requests/{$request['id']}")->assertOk()->json('data.events');
    expect(array_column($events, 'event'))->toBe(['created', 'sent', 'signed', 'signed', 'completed']);

    $event = ContractSignatureEvent::query()->firstOrFail();
    expect(fn () => $event->update(['event' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('a parallel request accepts any order, a refusal ends it and expiry is stored', function (): void {
    $version = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions", ['body' => 'Text'])->assertCreated()->json('data');
    $signers = [['name' => 'Ann', 'email' => 'ann@example.com'], ['name' => 'Bob', 'email' => 'bob@example.com']];

    $request = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", ['document_version_id' => $version['id'], 'signers' => $signers, 'order_mode' => 'parallel'])->assertCreated()->json('data');
    $tokens = $this->postJson("{$this->base}/signature-requests/{$request['id']}/send")->assertOk()->json('signing_tokens');
    [$ann, $bob] = array_column($request['signers'], 'id');

    $this->postJson("{$this->base}/signature-requests/{$request['id']}/sign", ['token' => $tokens[$bob]])->assertOk();
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/decline", ['token' => $tokens[$ann], 'reason' => 'Terms not agreed'])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/cancel")->assertUnprocessable();

    $second = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", ['document_version_id' => $version['id'], 'signers' => $signers, 'expires_on' => now()->addDays(2)->toDateString()])->assertCreated()->json('data');
    $secondTokens = $this->postJson("{$this->base}/signature-requests/{$second['id']}/send")->assertOk()->json('signing_tokens');
    $this->travel(5)->days();
    $this->postJson("{$this->base}/signature-requests/{$second['id']}/sign", ['token' => reset($secondTokens)])->assertUnprocessable();
    $this->getJson("{$this->base}/signature-requests/{$second['id']}")->assertJsonPath('data.status', 'expired');
});

test('a draft or sent request can be cancelled and an unknown provider is rejected', function (): void {
    $version = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/versions", ['body' => 'Text'])->assertCreated()->json('data');
    $payload = ['document_version_id' => $version['id'], 'signers' => [['name' => 'Ann', 'email' => 'ann@example.com']]];

    $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", [...$payload, 'provider' => 'unknown'])->assertUnprocessable();
    $this->getJson("{$this->base}/signature-providers")->assertOk()->assertJsonPath('data.0', 'internal');

    $request = $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", $payload)->assertCreated()->json('data');
    $this->postJson("{$this->base}/signature-requests/{$request['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("{$this->base}/contracts/customer/{$this->contractId}/signature-requests", $payload)->assertCreated();
});

test('read-only roles cannot use the authoring endpoints', function (): void {
    authoringActAs(authoringMember($this->tenant, 'accountant_readonly'), $this->tenant);

    $this->getJson("{$this->base}/clauses")->assertForbidden();
    $this->postJson("{$this->base}/clauses", ['code' => 'X', 'title' => 'X', 'body' => 'x'])->assertForbidden();
});
