<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Beekeeping\Models\Hive;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
    $this->apiary = beeApiary($this);
    $this->hive = beeHive($this, $this->apiary, 'H-1');
    $this->other = beeHive($this, $this->apiary, 'H-2');
});

it('calculates the end of the withdrawal from the treatment date and the days of the product', function (): void {
    $id = beeTreatment($this, $this->apiary, ['treatment_date' => '2026-06-10', 'product' => 'amitraz']);

    $this->getJson(BEE_API.'/varroa/treatments')->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.withdrawal_days', 30)
        ->assertJsonPath('data.0.withdrawal_end_date', '2026-07-10')
        ->assertJsonPath('data.0.withdrawal_active', true)
        ->assertJsonPath('data.0.active_substance', 'amitraz');
});

it('lets the veterinarian prescription override the default withdrawal days', function (): void {
    beeTreatment($this, $this->apiary, ['product' => 'thymol', 'withdrawal_days' => 14, 'treatment_date' => '2026-06-15']);

    $this->getJson(BEE_API.'/varroa/treatments')->assertJsonPath('data.0.withdrawal_end_date', '2026-06-29');
});

it('blocks placing honey supers on a hive of a treated apiary during the withdrawal', function (): void {
    beeTreatment($this, $this->apiary);

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'supers_blocked_by_withdrawal')
        ->assertJsonPath('context.until', '2026-07-10');

    expect($this->hive->fresh()->supers_count)->toBe(0);
});

it('blocks raising the supers of a hive from the registry during the withdrawal', function (): void {
    beeTreatment($this, $this->apiary);

    $this->putJson(BEE_API."/hives/{$this->hive->id}", ['supers_count' => 2])->assertUnprocessable()->assertJsonPath('code', 'supers_blocked_by_withdrawal');
});

it('blocks a honey harvest from a treated apiary and names the hives and the end date', function (): void {
    beeTreatment($this, $this->apiary);

    $this->postJson(BEE_API.'/intakes', ['apiary_id' => $this->apiary->id, 'harvested_on' => '2026-06-15', 'botanical_origin' => 'acacia', 'supers_count' => 4, 'gross_weight_kg' => 80, 'net_weight_kg' => 60])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'harvest_blocked_by_withdrawal')
        ->assertJsonPath('context.until', '2026-07-10');
});

it('locks only the treated hives when the treatment names some hives', function (): void {
    beeTreatment($this, $this->apiary, ['whole_apiary' => false, 'hive_ids' => [$this->hive->id]]);

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])->assertUnprocessable();
    $this->postJson(BEE_API."/hives/{$this->other->id}/inspections", ['supers_added' => 1])->assertCreated();

    $locks = $this->getJson(BEE_API."/apiaries/{$this->apiary->id}/locks")->assertOk()->json('data');
    expect($locks)->toHaveCount(1)->and($locks[0]['hive_code'])->toBe('H-1')->and($locks[0]['days_left'])->toBe(25);
});

it('shows the lock of each hive in the registry', function (): void {
    beeTreatment($this, $this->apiary, ['whole_apiary' => false, 'hive_ids' => [$this->hive->id]]);

    $rows = collect($this->getJson(BEE_API.'/hives?apiary_id='.$this->apiary->id)->assertOk()->json('data'))->keyBy('code');

    expect($rows['H-1']['withdrawal_lock']['until'])->toBe('2026-07-10')->and($rows['H-2']['withdrawal_lock'])->toBeNull();
});

it('lifts the lock on the day the withdrawal ends', function (): void {
    beeTreatment($this, $this->apiary);

    $this->travelTo('2026-07-09 09:00:00');
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])->assertUnprocessable();

    $this->travelTo('2026-07-10 09:00:00');
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])->assertCreated();
    $this->getJson(BEE_API."/apiaries/{$this->apiary->id}/locks")->assertOk()->assertJsonCount(0, 'data');
});

it('does not lock anything for a treatment without withdrawal period', function (): void {
    beeTreatment($this, $this->apiary, ['product' => 'oxalic_acid_sublimated', 'treatment_date' => '2026-06-15']);

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])->assertCreated();
});

it('does not lock a treatment dated in the future until its date', function (): void {
    beeTreatment($this, $this->apiary, ['treatment_date' => '2026-06-20']);

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['supers_added' => 1])->assertCreated();
});

it('requires the hives of a partial treatment and keeps them inside the apiary', function (): void {
    $this->postJson(BEE_API.'/varroa/treatments', ['apiary_id' => $this->apiary->id, 'treatment_date' => '2026-06-10', 'product' => 'amitraz', 'whole_apiary' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'treatment_hives_required');

    $elsewhere = beeApiary($this, ['code' => 'AP-002', 'latitude' => 46.5, 'longitude' => 9.9]);
    $foreign = beeHive($this, $elsewhere, 'H-9');

    $this->postJson(BEE_API.'/varroa/treatments', ['apiary_id' => $this->apiary->id, 'treatment_date' => '2026-06-10', 'product' => 'amitraz', 'whole_apiary' => false, 'hive_ids' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonPath('code', 'treatment_hive_outside_apiary');
});

it('records mite drop counts per hive', function (): void {
    $this->postJson(BEE_API.'/varroa/counts', ['hive_id' => $this->hive->id, 'counted_on' => '2026-06-14', 'count_type' => 'natural', 'mites_per_day' => 7.5])->assertCreated();

    $this->getJson(BEE_API.'/varroa/counts?hive_id='.$this->hive->id)->assertOk()->assertJsonPath('data.0.mites_per_day', '7.50')->assertJsonPath('data.0.count_type', 'natural');
});

it('prints the official veterinary register as a PDF', function (): void {
    beeTreatment($this, $this->apiary);

    $response = $this->get(BEE_API.'/documents/varroa-register?year=2026&locale=de');

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('keeps hives of other companies out of the register', function (): void {
    expect(Hive::query()->count())->toBe(2);
    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(BEE_API.'/varroa/treatments')->assertOk()->assertJsonCount(0, 'data');
});
