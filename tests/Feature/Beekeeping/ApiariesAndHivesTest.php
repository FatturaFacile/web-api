<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Beekeeping\Models\Apiary;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson(BEE_API.'/apiaries')->assertUnauthorized();
});

it('lets members and finance read, keeps writing to the managing roles and closes the module to the others', function (string $role, int $read, int $write): void {
    treasuryActor($this, $role);

    foreach (['lookups', 'dashboard', 'map', 'apiaries', 'hives', 'varroa/treatments', 'movements', 'census', 'batches'] as $path) {
        $this->getJson(BEE_API.'/'.$path)->assertStatus($read);
    }

    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload(['code' => 'AP-'.$role]))->assertStatus($write);
})->with([
    'owner' => ['owner', 200, 201],
    'admin' => ['admin', 200, 201],
    'accountant' => ['accountant', 200, 201],
    'member' => ['member', 200, 201],
    'read-only accountant' => ['accountant_readonly', 200, 403],
    'sales' => ['sales', 403, 403],
]);

it('registers an apiary with the default foraging radius of 3 km', function (): void {
    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload())
        ->assertCreated()
        ->assertJsonPath('data.foraging_radius_m', 3000)
        ->assertJsonPath('data.registry_code', '012BG123')
        ->assertJsonPath('data.latitude', 45.6983);
});

it('keeps the apiary code unique per company and lets another company reuse it', function (): void {
    beeApiary($this);

    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload())->assertUnprocessable()->assertJsonValidationErrors('code');

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload())->assertCreated();
});

it('requires latitude and longitude together and inside the WGS84 range', function (): void {
    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload(['longitude' => null]))->assertUnprocessable()->assertJsonValidationErrors('longitude');
    $this->postJson(BEE_API.'/apiaries', beeApiaryPayload(['code' => 'AP-X', 'latitude' => 95]))->assertUnprocessable()->assertJsonValidationErrors('latitude');
});

it('never shows the apiaries of another company', function (): void {
    $apiary = beeApiary($this);

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(BEE_API.'/apiaries')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(BEE_API."/apiaries/{$apiary->id}")->assertNotFound();
});

it('refuses to delete an apiary that still has hives', function (): void {
    $apiary = beeApiary($this);
    beeHive($this, $apiary, 'H-1');

    $this->deleteJson(BEE_API."/apiaries/{$apiary->id}")->assertUnprocessable()->assertJsonPath('code', 'apiary_has_hives');
});

it('derives the queen marking colour from the year with the international five year code', function (int $year, string $color): void {
    $apiary = beeApiary($this);

    beeHive($this, $apiary, 'H-'.$year, ['queen_year' => $year])->refresh();
    $this->getJson(BEE_API.'/hives?apiary_id='.$apiary->id)->assertOk()->assertJsonPath('data.0.queen_marking_color', $color);
})->with([
    [2021, 'white'], [2026, 'white'], [2022, 'yellow'], [2027, 'yellow'], [2023, 'red'], [2028, 'red'], [2024, 'green'], [2029, 'green'], [2025, 'blue'], [2030, 'blue'],
]);

it('flags nuclei, gives every hive a QR token and resolves a scanned QR code', function (): void {
    $apiary = beeApiary($this);
    $nucleus = beeHive($this, $apiary, 'N-1', ['hive_type' => 'nucleus']);

    $this->assertTrue($nucleus->is_nucleus);
    $this->assertNotSame('', $nucleus->qr_token);
    $this->getJson(BEE_API.'/hives/by-qr/'.$nucleus->qr_token)->assertOk()->assertJsonPath('data.code', 'N-1');
    $this->getJson(BEE_API.'/hives/by-qr/unknown')->assertNotFound();
});

it('keeps the hive code unique and the apiary inside the company', function (): void {
    $apiary = beeApiary($this);
    beeHive($this, $apiary, 'H-1');

    $this->postJson(BEE_API.'/hives', ['apiary_id' => $apiary->id, 'code' => 'H-1', 'hive_type' => 'langstroth'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $foreign = treasuryInTenant($this->otherTenant, fn (): Apiary => Apiary::query()->create(['code' => 'F-1', 'name' => 'Foreign', 'country' => 'IT', 'apiary_type' => 'stationary', 'foraging_radius_m' => 3000, 'tenant_id' => $this->otherTenant->id]));
    $this->postJson(BEE_API.'/hives', ['apiary_id' => $foreign->id, 'code' => 'H-2', 'hive_type' => 'langstroth'])->assertUnprocessable()->assertJsonValidationErrors('apiary_id');
});

it('raises the swarming and queenless alerts of a hive in the registry', function (): void {
    $apiary = beeApiary($this);
    beeHive($this, $apiary, 'H-1', ['brood_frames' => 9, 'supers_count' => 0, 'queen_status' => 'missing', 'strength_score' => 2]);

    $alerts = $this->getJson(BEE_API.'/hives?apiary_id='.$apiary->id)->assertOk()->json('data.0.alerts');

    expect($alerts)->toContain('swarming_risk', 'queenless', 'weak_colony');
});

it('draws the map with the apiaries and the overlapping foraging circles', function (): void {
    beeApiary($this, ['code' => 'A', 'latitude' => 45.7000, 'longitude' => 9.6700]);
    beeApiary($this, ['code' => 'B', 'latitude' => 45.7100, 'longitude' => 9.6800]); // about 1.4 km away: the 3 km circles overlap
    beeApiary($this, ['code' => 'C', 'latitude' => 46.5000, 'longitude' => 9.9000]); // far away
    beeApiary($this, ['code' => 'D', 'latitude' => null, 'longitude' => null]);

    $map = $this->getJson(BEE_API.'/map')->assertOk()->json('data');

    expect($map['apiaries'])->toHaveCount(4)->and($map['overlaps'])->toHaveCount(1);
    expect($map['overlaps'][0]['distance_m'])->toBeLessThan(6000)->and($map['overlaps'][0]['overlap_percent'])->toBeGreaterThan(0);
    expect(collect($map['apiaries'])->firstWhere('code', 'A')['overlap_count'])->toBe(1);
    expect(collect($map['apiaries'])->firstWhere('code', 'C')['overlap_count'])->toBe(0);
});

it('counts hives and nuclei on the dashboard', function (): void {
    $apiary = beeApiary($this);
    beeHive($this, $apiary, 'H-1');
    beeHive($this, $apiary, 'H-2', ['status' => 'orphaned']);
    beeHive($this, $apiary, 'N-1', ['hive_type' => 'nucleus']);

    $this->getJson(BEE_API.'/dashboard')->assertOk()->assertJsonPath('data.apiaries', 1)->assertJsonPath('data.hives', 2)->assertJsonPath('data.nuclei', 1)->assertJsonPath('data.orphaned_hives', 1);
});

it('prints the QR label sheet of the hives as a PDF', function (): void {
    $apiary = beeApiary($this);
    beeHive($this, $apiary, 'H-1');

    $response = $this->get(BEE_API.'/documents/hive-qr-sheet?apiary_id='.$apiary->id.'&locale=it');

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});
