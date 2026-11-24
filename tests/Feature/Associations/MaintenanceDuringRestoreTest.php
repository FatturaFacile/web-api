<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\Member;
use Modules\Associations\Services\AccessDeviceService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    $this->withoutMiddleware(ThrottleRequests::class);
});

afterEach(fn () => associationsTearDown());

test('during a restore the member area still reads but refuses writes with 503', function (): void {
    $member = Member::factory()->create();
    $token = areaToken($member->contact);
    $this->tenant->forceFill(['is_maintenance' => true])->save();

    $this->getJson('/api/v1/association-area/me', areaHeaders($token))->assertOk();
    $this->putJson('/api/v1/association-area/me/contact', ['phone' => '+39 02 1234567'], areaHeaders($token))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60');
});

test('during a restore a reader cannot record an access', function (): void {
    $registered = app(AccessDeviceService::class)->register(['area_id' => AccessArea::factory()->create()->id, 'name' => 'Gate 1']);
    $this->tenant->forceFill(['is_maintenance' => true])->save();

    $this->withHeaders(['X-Device-Key' => $registered['device']->device_key, 'X-Device-Secret' => $registered['secret']])
        ->postJson('/api/v1/association-access/verify', ['credential' => 'anything'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60');
});

test('a reader with a wrong secret is still refused as unauthenticated during a restore', function (): void {
    $registered = app(AccessDeviceService::class)->register(['area_id' => AccessArea::factory()->create()->id, 'name' => 'Gate 1']);
    $this->tenant->forceFill(['is_maintenance' => true])->save();

    $this->withHeaders(['X-Device-Key' => $registered['device']->device_key, 'X-Device-Secret' => 'wrong'])
        ->postJson('/api/v1/association-access/verify', ['credential' => 'anything'])
        ->assertUnauthorized();
});
