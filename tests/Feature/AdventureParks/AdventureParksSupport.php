<?php

declare(strict_types=1);

use Database\Seeders\AdventureParksReferenceSeeder;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Invoicing/InvoicingBillingSupport.php';

const AP_API = '/api/v1/adventure-parks';

/**
 * Two Italian companies with an accountant signed in on the first one. The clock stands on Monday 15 June 2026. The global
 * reference data (waiver text and workshop catalogue) is seeded.
 */
function apSetUp(object $test): void
{
    $test->travelTo('2026-06-15 09:00:00');
    billingSetUp($test);
    (new AdventureParksReferenceSeeder)->run();
}

/**
 * A slot template; returns its id.
 *
 * @param  array<string, mixed>  $extra
 */
function apSlot(object $test, string $start = '10:00', string $end = '12:00', array $extra = []): string
{
    return (string) $test->postJson(AP_API.'/slots', array_merge(['start_time' => $start, 'end_time' => $end], $extra))->assertCreated()->json('data.id');
}

/**
 * A batch of harnesses of one size (serials PREFIX-000001, ...); returns the serial codes.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function apHarnesses(object $test, string $size, int $count, ?string $prefix = null, array $overrides = []): array
{
    $response = $test->postJson(AP_API.'/ppe/batch', array_merge([
        'category' => 'HARNESS',
        'size' => $size,
        'prefix' => $prefix ?? strtoupper(substr($size, 0, 2)),
        'count' => $count,
        'brand' => 'Petzl',
        'model' => 'Pro',
        'batch_number' => 'LOT-1',
        'purchase_date' => '2026-01-10',
        'manufacturer_max_lifespan_date' => '2030-12-31',
        'next_inspection_due_date' => '2027-01-01',
    ], $overrides))->assertCreated();

    return array_values(array_map(static fn (array $row): string => (string) $row['serial_code'], $response->json('data')));
}

/**
 * @param  array<string, int>  $quantities  size => quantity
 * @param  array<string, mixed>  $overrides
 */
function apBook(object $test, string $slotId, string $date, array $quantities, array $overrides = []): TestResponse
{
    $lines = [];

    foreach ($quantities as $size => $quantity) {
        $lines[] = ['size' => $size, 'quantity' => $quantity];
    }

    return $test->postJson(AP_API.'/tickets', array_merge(['slot_id' => $slotId, 'slot_date' => $date, 'contact_name' => 'Famiglia Rossi', 'lines' => $lines], $overrides));
}

/**
 * Payload of the public waiver signing: an adult of 175 cm / 70 kg (ADULT) and `$minors` children of 125 cm / 30 kg (JUNIOR).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apSignPayload(int $minors = 0, array $overrides = []): array
{
    $children = [];

    for ($i = 1; $i <= $minors; $i++) {
        $children[] = ['full_name' => 'Figlio '.$i, 'birth_date' => '2016-03-0'.$i, 'height_cm' => 125, 'weight_kg' => 30];
    }

    return array_merge([
        'signer' => ['full_name' => 'Mario Rossi', 'fiscal_code' => 'RSSMRA80A01H501U', 'phone' => '+39 333 1234567', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01', 'height_cm' => 175, 'weight_kg' => 70],
        'minors' => $children,
        'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        'accepted_rules' => true,
        'locale' => 'it',
    ], $overrides);
}

/**
 * Books the slot, signs the waiver of the ticket and returns the ticket.
 *
 * @param  array<string, int>  $quantities
 * @return array<string, mixed>
 */
function apSignedTicket(object $test, string $slotId, string $date, array $quantities, int $minors): array
{
    $ticket = apBook($test, $slotId, $date, $quantities)->assertCreated()->json('data');
    $test->postJson(AP_API.'/public/waiver/'.$ticket['public_token'].'/sign', apSignPayload($minors))->assertOk();

    return $test->getJson(AP_API.'/tickets/'.$ticket['id'])->assertOk()->json('data');
}

/**
 * @param  array<string, mixed>  $ticket
 * @return array<string, string> participant full name => id
 */
function apParticipants(array $ticket): array
{
    $result = [];

    foreach ($ticket['participants'] as $participant) {
        $result[(string) $participant['full_name']] = (string) $participant['id'];
    }

    return $result;
}
