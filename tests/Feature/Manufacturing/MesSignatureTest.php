<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mesSetUp($this);
});

const MES_MACHINES_URI = '/api/v1/manufacturing/mes-sync/machines';

test('a valid signature reaches the endpoint and the answer is scoped to the tenant', function (): void {
    mesCall($this, 'GET', MES_MACHINES_URI)->assertOk()->assertJsonPath('resource', 'machines');
});

test('a missing or malformed signature is a 401 problem document', function (): void {
    $this->getJson(MES_MACHINES_URI)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'mes.signature.invalid')
        ->assertJsonPath('status', 401);

    $this->withHeader('X-Mes-Signature', 't=abc,kid=k1,v1=zz')->getJson(MES_MACHINES_URI)->assertUnauthorized()->assertJsonPath('code', 'mes.signature.invalid');
});

test('an expired or future timestamp is refused', function (): void {
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['timestamp' => time() - 301])->assertUnauthorized()->assertJsonPath('code', 'mes.signature.expired');
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['timestamp' => time() + 301])->assertUnauthorized()->assertJsonPath('code', 'mes.signature.expired');
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['timestamp' => time() - 299])->assertOk();
});

test('a tampered body or a wrong secret is refused', function (): void {
    $body = ['events' => [['event_id' => 'x']]];

    mesCall($this, 'POST', '/api/v1/manufacturing/mes-feedback/events', $body, ['signed_body' => '{"events":[]}'])->assertUnauthorized()->assertJsonPath('code', 'mes.signature.invalid');
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['secret' => 'not-the-secret'])->assertUnauthorized()->assertJsonPath('code', 'mes.signature.invalid');
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['kid' => 'unknown', 'secret' => 'secret-one'])->assertUnauthorized()->assertJsonPath('code', 'mes.signature.invalid');
});

test('two keys are valid at once, so a key can be rotated', function (): void {
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['kid' => 'k1'])->assertOk();
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['kid' => 'k2'])->assertOk();

    config(['manufacturing_mes.keys' => ['k2' => config('manufacturing_mes.keys.k2')]]);

    mesCall($this, 'GET', MES_MACHINES_URI, null, ['kid' => 'k1', 'secret' => 'secret-one'])->assertUnauthorized();
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['kid' => 'k2'])->assertOk();
});

test('a valid key never reaches a tenant it is not bound to', function (): void {
    mesCall($this, 'GET', MES_MACHINES_URI, null, ['tenant' => (string) $this->otherTenant->id])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'mes.tenant.forbidden');

    mesCall($this, 'GET', MES_MACHINES_URI, null, ['tenant' => null])->assertStatus(400)->assertJsonPath('code', 'mes.tenant.missing');
});

test('an event of another tenant than the signing key is rejected without failing the batch', function (): void {
    $operation = mesOperation($this);
    $foreign = mesSnapshotEvent($this, $operation, 1, ['tenant_id' => (string) $this->otherTenant->id]);
    $own = mesSnapshotEvent($this, $operation, 1);

    $response = mesCall($this, 'POST', '/api/v1/manufacturing/mes-feedback/events', ['events' => [$foreign, $own]])->assertStatus(207);

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and($response->json('results.0.problem.status'))->toBe(403)
        ->and($response->json('results.1.status'))->toBe('applied');
});
