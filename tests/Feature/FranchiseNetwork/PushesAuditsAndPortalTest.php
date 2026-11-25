<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/FranchiseNetworkSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fnetSetUp($this);
});

/**
 * Links a store to the franchisee company through its code and returns to the franchisor session.
 *
 * @param  array<string, mixed>  $store
 */
function fnetLink(object $test, array $store): void
{
    $code = $test->postJson('/api/v1/franchise-network/stores/'.$store['id'].'/link-code')->json('data.code');
    fnetActAs($test, 'franchisee_owner', $test->franchiseeTenant);
    $test->postJson('/api/v1/franchise-network/portal/link', ['code' => $code])->assertOk()->assertJsonPath('data.linked', true);
    fnetActAs($test, 'owner');
}

it('pushes a catalogue to every store and turns a maximum price into a recommendation in Switzerland', function (): void {
    $swiss = fnetStore($this, 'PU-01', ['country' => 'CH', 'postal_code' => '8001']);
    fnetLink($this, $swiss);

    $push = $this->postJson('/api/v1/franchise-network/pushes', [
        'push_type' => 'catalog', 'title' => 'Spring', 'target_mode' => 'all', 'dispatch' => true,
        'payload' => ['items' => [['sku' => 'JKT', 'name' => 'Jacket', 'price' => 120, 'price_type' => 'maximum']]],
    ])->assertCreated()->json('data');

    expect($push['status'])->toBe('synced');

    fnetActAs($this, 'franchisee_owner', $this->franchiseeTenant);
    $sync = $this->getJson('/api/v1/franchise-network/portal/syncs')->assertOk()->json('data.0');

    expect($sync['push']['payload']['items'][0]['price_type'])->toBe('recommended')
        ->and($sync['push']['payload']['items'][0]['non_binding'])->toBeTrue();

    $this->postJson('/api/v1/franchise-network/portal/syncs/'.$sync['id'].'/acknowledge')->assertOk()->assertJsonPath('data.status', 'acknowledged');
});

it('refuses a push without any target store', function (): void {
    $this->postJson('/api/v1/franchise-network/pushes', [
        'push_type' => 'promotion', 'title' => 'Sale', 'target_mode' => 'all',
        'payload' => ['promotion' => ['name' => 'Sale', 'valid_from' => now()->toDateString(), 'valid_to' => now()->addWeek()->toDateString()]],
    ])->assertStatus(422)->assertJsonPath('code', 'push_no_targets');
});

it('refuses to retry a push that has nothing failed', function (): void {
    $store = fnetStore($this, 'PU-02');
    $push = $this->postJson('/api/v1/franchise-network/pushes', [
        'push_type' => 'catalog', 'title' => 'Basics', 'target_mode' => 'selected', 'store_ids' => [$store['id']],
        'payload' => ['items' => [['sku' => 'A', 'name' => 'A']]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/franchise-network/pushes/'.$push['id'].'/dispatch')->assertOk();
    $this->postJson('/api/v1/franchise-network/pushes/'.$push['id'].'/retry')->assertStatus(409)->assertJsonPath('code', 'push_nothing_to_retry');
});

it('runs an audit from the draft to the corrective actions and then locks it', function (): void {
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    $store = fnetStore($this, 'AU-01');
    $template = collect($this->getJson('/api/v1/franchise-network/lookups')->json('data.audit_templates'))->first();

    $audit = $this->postJson('/api/v1/franchise-network/audits', ['store_id' => $store['id'], 'template_id' => $template['id']])->assertCreated()->json('data');

    $this->postJson('/api/v1/franchise-network/audits/'.$audit['id'].'/submit')->assertStatus(422)->assertJsonPath('code', 'audit_incomplete');

    $criteria = $template['criteria'];
    $answers = collect($criteria)->map(fn (array $criterion, int $index): array => ['criterion_code' => $criterion['code'], 'score' => $index === 0 ? 0 : $criterion['max_score'] ?? 5])->all();
    $this->putJson('/api/v1/franchise-network/audits/'.$audit['id'].'/answers', ['answers' => $answers])->assertOk();

    $this->post('/api/v1/franchise-network/audits/'.$audit['id'].'/photos', ['photo' => UploadedFile::fake()->image('shop.jpg')], ['Accept' => 'application/json'])->assertCreated();

    $submitted = $this->postJson('/api/v1/franchise-network/audits/'.$audit['id'].'/submit')->assertOk()->json('data');
    expect($submitted['status'])->toBe('submitted');

    $this->getJson('/api/v1/franchise-network/audit-actions')->assertOk()->assertJsonFragment(['criterion_code' => $criteria[0]['code']]);
    $this->putJson('/api/v1/franchise-network/audits/'.$audit['id'].'/answers', ['answers' => $answers])->assertStatus(409)->assertJsonPath('code', 'audit_locked');
});

it('rejects an unknown criterion and an out-of-range score', function (): void {
    $store = fnetStore($this, 'AU-02');
    $template = collect($this->getJson('/api/v1/franchise-network/lookups')->json('data.audit_templates'))->first();
    $audit = $this->postJson('/api/v1/franchise-network/audits', ['store_id' => $store['id'], 'template_id' => $template['id']])->json('data');

    $this->putJson('/api/v1/franchise-network/audits/'.$audit['id'].'/answers', ['answers' => [['criterion_code' => 'NOPE', 'score' => 1]]])->assertStatus(422)->assertJsonPath('code', 'audit_criterion_unknown');
});

it('binds a store to the franchisee company with the link code and shows it in the portal', function (): void {
    $store = fnetStore($this, 'PO-01');
    fnetLink($this, $store);

    fnetActAs($this, 'franchisee_owner', $this->franchiseeTenant);
    $this->getJson('/api/v1/franchise-network/portal/me')->assertOk()->assertJsonPath('data.stores.0.code', 'PO-01');
});

it('refuses a wrong link code and a code redeemed by the franchisor itself', function (): void {
    $store = fnetStore($this, 'PO-02');
    $code = $this->postJson('/api/v1/franchise-network/stores/'.$store['id'].'/link-code')->json('data.code');

    $this->postJson('/api/v1/franchise-network/portal/link', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'link_code_same_company');

    fnetActAs($this, 'franchisee_owner', $this->franchiseeTenant);
    $this->postJson('/api/v1/franchise-network/portal/link', ['code' => 'WRONG-CODE'])->assertStatus(404)->assertJsonPath('code', 'link_code_invalid');
});

it('denies the portal to a company that has no linked store', function (): void {
    fnetStore($this, 'PO-03');

    fnetActAs($this, 'franchisee_owner', $this->otherTenant);
    $this->getJson('/api/v1/franchise-network/portal/contract')->assertStatus(403)->assertJsonPath('code', 'portal_not_linked');
});

it('shows the franchisee its own contract and statements but never the stores of another franchisee', function (): void {
    $formula = fnetFormula($this);
    $mine = fnetStore($this, 'PO-04');
    $other = fnetStore($this, 'PO-05');
    fnetAgreement($this, $mine['id'], $formula['id']);
    fnetAgreement($this, $other['id'], $formula['id']);
    fnetLink($this, $mine);

    fnetActAs($this, 'franchisee_owner', $this->franchiseeTenant);
    $this->getJson('/api/v1/franchise-network/portal/contract')->assertOk()->assertJsonPath('data.agreement.store_id', $mine['id']);
    $this->getJson('/api/v1/franchise-network/portal/me')->assertOk()->assertJsonMissing(['code' => 'PO-05']);
    $this->getJson('/api/v1/franchise-network/stores')->assertForbidden();
});

it('hides the cluster aggregate of the benchmark when fewer than the minimum peers share it', function (): void {
    $formula = fnetFormula($this);
    $store = fnetStore($this, 'BM-01');
    fnetAgreement($this, $store['id'], $formula['id']);
    fnetSellOut($this, $store['id'], $this->period);
    fnetLink($this, $store);

    $this->postJson('/api/v1/franchise-network/benchmark/snapshot', ['period' => $this->period])->assertSuccessful();

    fnetActAs($this, 'franchisee_owner', $this->franchiseeTenant);
    $this->getJson('/api/v1/franchise-network/portal/benchmark?period='.$this->period)->assertOk()->assertJsonPath('data.cluster_hidden', true);
});
