<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IndustrialLaundry\Models\Garment;

require_once __DIR__.'/IndustrialLaundrySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ilrSetUp($this);
});

/**
 * Receives the soiled garments at the gate and returns the id of the scan session.
 *
 * @param  list<int>  $numbers
 */
function ilrReceiveSoiled(object $test, array $numbers, string $cage = 'RC-0001'): string
{
    $response = $test->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload($numbers, ['roll_cage_barcode' => $cage]))->assertCreated();

    return (string) $response->json('data.id');
}

it('groups the soiled roll cages into a batch of the wash line and moves their garments into the tunnel', function (): void {
    ilrGarment($this, 1);
    ilrGarment($this, 2);
    $session = ilrReceiveSoiled($this, [1, 2]);

    $response = $this->postJson('/api/v1/industrial-laundry/wash-batches', [
        'program_code' => 'THERMAL_71_3MIN', 'rabc_risk_zone' => 'contaminated', 'scan_session_ids' => [$session], 'operator_name' => 'Luca Neri', 'batch_code' => 'B-1',
    ])->assertCreated();

    expect($response->json('data.batch_code'))->toBe('B-1')
        ->and($response->json('data.rabc_control_status'))->toBe('pending')
        ->and($response->json('data.garments_count'))->toBe(2)
        ->and($response->json('data.target_temperature_c'))->toBe('71.00')
        ->and($response->json('data.target_hold_minutes'))->toBe(3)
        ->and($response->json('data.load_net_weight_kg'))->toBe('80.000')
        ->and($response->json('data.scan_session_ids'))->toBe([$session])
        ->and(Garment::query()->where('current_lifecycle_state', 'in_wash_tunnel')->count())->toBe(2);

    $this->getJson('/api/v1/industrial-laundry/wash-batches')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/wash-batches/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.batch_code', 'B-1');
});

it('names the batches by day when no code is given', function (): void {
    ilrGarment($this, 1);
    ilrGarment($this, 2);

    $first = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [ilrReceiveSoiled($this, [1])]])->assertCreated();
    $second = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [ilrReceiveSoiled($this, [2], 'RC-0002')]])->assertCreated();

    expect($first->json('data.batch_code'))->toBe('RABC-'.now()->format('Ymd').'-001')
        ->and($second->json('data.batch_code'))->toBe('RABC-'.now()->format('Ymd').'-002');
});

it('refuses a batch with an unknown program, no sessions, outbound sessions, a taken code or sessions already in a batch', function (): void {
    ilrGarment($this, 1);
    $session = ilrReceiveSoiled($this, [1]);
    $outbound = (string) $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([], ['direction' => 'outbound_clean', 'roll_cage_barcode' => 'RC-0009']))->assertCreated()->json('data.id');

    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'NOPE', 'scan_session_ids' => [$session]])->assertUnprocessable()->assertJsonPath('code', 'program_unknown');
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => []])->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [$outbound]])->assertUnprocessable()->assertJsonPath('code', 'batch_sessions_invalid');
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [(string) Str::uuid()]])->assertUnprocessable()->assertJsonPath('code', 'batch_sessions_invalid');
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'rabc_risk_zone' => 'moon', 'scan_session_ids' => [$session]])->assertUnprocessable();

    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'batch_code' => 'B-1', 'scan_session_ids' => [$session]])->assertCreated();
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [$session]])->assertUnprocessable()->assertJsonPath('code', 'session_already_batched');

    $other = ilrGarment($this, 2);
    $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'batch_code' => 'B-1', 'scan_session_ids' => [ilrReceiveSoiled($this, [2], 'RC-0002')]])
        ->assertUnprocessable()->assertJsonPath('code', 'batch_code_taken');

    expect($other->refresh()->current_lifecycle_state->value)->toBe('soiled_returned');
});

it('holds the garments of an uncertified batch in the tunnel and releases them once the batch is compliant', function (): void {
    ilrGarment($this, 1);
    $batch = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'THERMAL_71_3MIN', 'scan_session_ids' => [ilrReceiveSoiled($this, [1])]])->assertCreated();

    $refused = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'outbound_clean', 'roll_cage_barcode' => 'RC-0002']))->assertCreated();

    expect($refused->json('data.accepted_count'))->toBe(0)
        ->and($refused->json('data.rejected_reads.0.reason'))->toBe('rabc_not_certified');

    $certified = $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$batch->json('data.id').'/certify', [
        'measured_temperature_c' => '72.5', 'measured_hold_minutes' => 4, 'microbiological_cfu_per_25cm2' => '5', 'verifier_name' => 'Dott.ssa Rossi',
    ])->assertOk();

    expect($certified->json('data.rabc_control_status'))->toBe('compliant')
        ->and($certified->json('data.violations'))->toBe([])
        ->and($certified->json('data.certified_at'))->not->toBeNull()
        ->and($certified->json('data.verifier_name'))->toBe('Dott.ssa Rossi');

    $released = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'outbound_clean', 'roll_cage_barcode' => 'RC-0003']))->assertCreated();

    expect($released->json('data.accepted_count'))->toBe(1)
        ->and(Garment::query()->firstOrFail()->current_lifecycle_state->value)->toBe('delivered_to_customer');

    $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$batch->json('data.id').'/certify', ['verifier_name' => 'X'])->assertUnprocessable()->assertJsonPath('code', 'batch_already_certified');
});

it('fails a batch that did not reach the disinfection targets and washes its garments again in a new batch', function (): void {
    ilrGarment($this, 1);
    $session = ilrReceiveSoiled($this, [1]);
    $batch = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'CHEMOTHERMAL_60_PAA', 'rabc_risk_zone' => 'contaminated', 'scan_session_ids' => [$session]])->assertCreated();

    $failed = $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$batch->json('data.id').'/certify', [
        'measured_temperature_c' => '55', 'measured_hold_minutes' => 5, 'measured_chemical_dosing_ml_per_kg' => '10', 'microbiological_cfu_per_25cm2' => '45', 'verifier_name' => 'Dott.ssa Rossi',
    ])->assertOk();

    expect($failed->json('data.rabc_control_status'))->toBe('non_compliant')
        ->and($failed->json('data.certified_at'))->toBeNull()
        ->and(collect($failed->json('data.violations'))->pluck('code')->all())->toBe([
            'rabc_temperature_below_target', 'rabc_hold_time_below_target', 'rabc_chemical_dosing_below_target', 'rabc_microbial_count_exceeded',
        ])
        ->and(Garment::query()->firstOrFail()->current_lifecycle_state->value)->toBe('soiled_returned');

    $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$batch->json('data.id').'/certify', ['verifier_name' => 'X'])->assertUnprocessable()->assertJsonPath('code', 'batch_not_pending');

    $again = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'THERMAL_85_15MIN', 'scan_session_ids' => [$session]])->assertCreated();

    expect($again->json('data.garments_count'))->toBe(1)
        ->and(Garment::query()->firstOrFail()->current_lifecycle_state->value)->toBe('in_wash_tunnel');
});

it('does not accept a measure missing from a thermal program, nor a plain wash for a contaminated zone', function (): void {
    ilrGarment($this, 1);
    ilrGarment($this, 2);

    $thermal = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'THERMAL_71_3MIN', 'scan_session_ids' => [ilrReceiveSoiled($this, [1])]])->assertCreated();
    $plain = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'rabc_risk_zone' => 'protected', 'scan_session_ids' => [ilrReceiveSoiled($this, [2], 'RC-0002')]])->assertCreated();

    $missing = $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$thermal->json('data.id').'/certify', ['verifier_name' => 'Dott.ssa Rossi'])->assertOk();
    $notDisinfecting = $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$plain->json('data.id').'/certify', ['verifier_name' => 'Dott.ssa Rossi'])->assertOk();

    expect($missing->json('data.rabc_control_status'))->toBe('non_compliant')
        ->and(collect($missing->json('data.violations'))->pluck('code')->all())->toBe(['rabc_temperature_below_target', 'rabc_hold_time_below_target'])
        ->and($notDisinfecting->json('data.rabc_control_status'))->toBe('non_compliant')
        ->and($notDisinfecting->json('data.violations.0.code'))->toBe('rabc_program_not_disinfecting');

    $this->postJson('/api/v1/industrial-laundry/wash-batches/'.$thermal->json('data.id').'/certify', [])->assertUnprocessable();
});

it('filters the batches by status and keeps them away from other companies', function (): void {
    ilrGarment($this, 1);
    $batch = $this->postJson('/api/v1/industrial-laundry/wash-batches', ['program_code' => 'STANDARD_60', 'scan_session_ids' => [ilrReceiveSoiled($this, [1])]])->assertCreated();

    $this->getJson('/api/v1/industrial-laundry/wash-batches?status=pending')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/wash-batches?status=compliant')->assertOk()->assertJsonCount(0, 'data');

    $other = Tenant::create(['name' => 'Lavanderia Beta', 'legal_name' => 'Lavanderia Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson('/api/v1/industrial-laundry/wash-batches/'.$batch->json('data.id'))->assertNotFound();
    $this->getJson('/api/v1/industrial-laundry/wash-batches')->assertOk()->assertJsonCount(0, 'data');
});
