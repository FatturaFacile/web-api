<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\NetworkLink;
use Modules\Associations\Services\MembershipService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->parent = $this->tenant;
    $this->affiliate = $this->otherTenant;

    inTenant($this->affiliate, function (): void {
        $service = app(MembershipService::class);
        $service->activate($service->request(Member::factory()->create(), AssociativeYear::factory()->create(), MembershipType::factory()->create()));
        Member::factory()->create();
    });

    actAs($this, 'admin', tenant: $this->affiliate);
    $this->code = $this->getJson('/api/v1/associations/network/code')->assertOk()->json('data.affiliation_code');
});

afterEach(fn () => associationsTearDown());

function affiliationRequested(object $test): void
{
    actAs($test, 'admin', tenant: $test->parent);
    $test->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => $test->code])->assertCreated();
}

function consentGiven(object $test): string
{
    affiliationRequested($test);
    actAs($test, 'admin', tenant: $test->affiliate);
    $id = $test->getJson('/api/v1/associations/network/consents')->json('data.0.id');
    $test->postJson("/api/v1/associations/network/consents/{$id}/grant")->assertOk();

    return $id;
}

function figuresOfParent(object $test): array
{
    actAs($test, 'admin', tenant: $test->parent);

    return $test->getJson('/api/v1/associations/network/figures')->assertOk()->json('data');
}

test('without any link nothing is visible', function (): void {
    expect(figuresOfParent($this))->toBe(['affiliates' => [], 'totals' => ['members' => 0, 'active_memberships' => 0, 'active_courses' => 0, 'course_participants' => 0]]);
});

test('a request without consent shows nothing', function (): void {
    affiliationRequested($this);

    expect(figuresOfParent($this)['affiliates'])->toBe([]);
});

test('after the consent the parent reads aggregated figures, and no person', function (): void {
    consentGiven($this);

    $figures = figuresOfParent($this);

    expect($figures['affiliates'])->toHaveCount(1)
        ->and($figures['affiliates'][0]['name'])->toBe($this->affiliate->name)
        ->and($figures['totals']['members'])->toBe(2)
        ->and($figures['totals']['active_memberships'])->toBe(1);

    $names = inTenant($this->affiliate, fn () => Member::query()->with('contact')->get()->map(fn (Member $member): string => $member->contact->last_name)->all());

    expect(json_encode($figures))->not->toContain($names[0])->not->toContain($names[1]);
});

test('withdrawing the consent closes the view at once', function (): void {
    $id = consentGiven($this);

    expect(figuresOfParent($this)['affiliates'])->toHaveCount(1);

    actAs($this, 'admin', tenant: $this->affiliate);
    $this->postJson("/api/v1/associations/network/consents/{$id}/withdraw")->assertOk();

    expect(figuresOfParent($this)['affiliates'])->toBe([]);
});

test('only the affiliate can give the consent', function (): void {
    affiliationRequested($this);
    $link = NetworkLink::withoutGlobalScopes()->firstOrFail();

    $this->postJson("/api/v1/associations/network/consents/{$link->id}/grant")->assertNotFound();

    expect($link->refresh()->status)->toBe(NetworkLink::Pending)
        ->and($this->getJson('/api/v1/associations/network/consents')->json('data'))->toBe([]);
});

test('a third body sees nothing of a consent given to somebody else', function (): void {
    consentGiven($this);
    $third = Tenant::create(['name' => 'Terzo', 'legal_name' => 'Terzo']);

    actAs($this, 'admin', tenant: $third);

    expect($this->getJson('/api/v1/associations/network/figures')->json('data.affiliates'))->toBe([]);
});

test('the parent keeps its own tenant: it reads no record of the affiliate through the ordinary endpoints', function (): void {
    consentGiven($this);
    $affiliateMember = inTenant($this->affiliate, fn () => Member::query()->firstOrFail());

    actAs($this, 'admin', tenant: $this->parent);

    $this->getJson("/api/v1/associations/members/{$affiliateMember->id}")->assertNotFound();
    expect($this->getJson('/api/v1/associations/members')->json('data'))->toBe([]);
});

test('an unknown code, the own code and a second request are refused', function (): void {
    actAs($this, 'admin', tenant: $this->parent);

    $this->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => str_repeat('a', 32)])->assertUnprocessable();
    $this->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => $this->code])->assertCreated();
    $this->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => $this->code])->assertConflict();

    actAs($this, 'admin', tenant: $this->affiliate);
    $this->postJson('/api/v1/associations/network/affiliates', ['affiliation_code' => $this->code])->assertUnprocessable();
});

test('the network is for owners and administrators', function (string $role): void {
    actAs($this, $role, tenant: $this->parent);

    $this->getJson('/api/v1/associations/network/figures')->assertForbidden();
})->with(['secretary', 'accountant', 'member']);
