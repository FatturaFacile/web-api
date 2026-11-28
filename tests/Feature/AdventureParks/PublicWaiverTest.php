<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AdventureParksSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
    $this->slot = apSlot($this, '10:00', '12:00');
    apHarnesses($this, 'ADULT', 3);
    apHarnesses($this, 'JUNIOR', 3);
});

it('serves the legal text of the waiver from the database in the language asked for', function (): void {
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1, 'JUNIOR' => 1])->assertCreated()->json('data');

    $it = $this->getJson(AP_API.'/public/waiver/'.$ticket['public_token'].'?locale=it')->assertOk()
        ->assertJsonPath('data.signed', false)
        ->assertJsonPath('data.ticket.booking_code', $ticket['booking_code'])
        ->assertJsonPath('data.ticket.headcount', 2)
        ->assertJsonPath('data.ticket.start_time', '10:00')
        ->assertJsonPath('data.waiver.version_id', 'v1')
        ->assertJsonPath('data.waiver.locale', 'it')
        ->assertJsonPath('data.qr_payload', $ticket['public_token'])
        ->assertJsonCount(4, 'data.size_table')
        ->json('data.waiver.template');

    expect($it['title'])->toBe('Dichiarazione di assunzione del rischio e manleva')
        ->and($it['body_html_paragraphs'])->toHaveCount(5)
        ->and($it['rules'])->toHaveCount(8);

    foreach (['en' => 'Assumption of risk and liability waiver', 'de' => 'Risikoübernahme und Haftungsverzicht', 'fr' => 'Acceptation du risque et décharge de responsabilité'] as $locale => $title) {
        $this->getJson(AP_API.'/public/waiver/'.$ticket['public_token'].'?locale='.$locale)->assertOk()->assertJsonPath('data.waiver.template.title', $title);
    }

    $this->getJson(AP_API.'/public/waiver/'.str_repeat('x', 40))->assertNotFound()->assertJsonPath('code', 'ticket_not_found');
});

it('signs the waiver for the signer and the minors, recommends the sizes and stores the evidence', function (): void {
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1, 'JUNIOR' => 2])->assertCreated()->json('data');

    $response = $this->withHeader('User-Agent', 'Kiosk/1.0')->postJson(AP_API.'/public/waiver/'.$ticket['public_token'].'/sign', apSignPayload(2, ['locale' => 'de']))
        ->assertOk()
        ->assertJsonPath('data.signed', true)
        ->assertJsonPath('data.qr_payload', $ticket['public_token'])
        ->assertJsonCount(3, 'data.participants');

    $participants = collect($response->json('data.participants'));
    expect($participants->pluck('size', 'full_name')->all())->toEqual(['Mario Rossi' => 'ADULT', 'Figlio 1' => 'JUNIOR', 'Figlio 2' => 'JUNIOR'])
        ->and($participants->firstWhere('full_name', 'Figlio 1')['is_minor'])->toBeTrue()
        ->and($participants->firstWhere('full_name', 'Mario Rossi')['is_minor'])->toBeFalse();

    $row = DB::table('adv_safety_waivers')->where('ticket_id', $ticket['id'])->first();
    expect($row->signer_full_name)->toBe('Mario Rossi')
        ->and($row->locale)->toBe('de')
        ->and($row->waiver_version_id)->toBe('v1')
        ->and((bool) $row->accepted_rules)->toBeTrue()
        ->and($row->ip_address)->not->toBeNull()
        ->and($row->user_agent)->toBe('Kiosk/1.0')
        ->and($row->signed_at)->not->toBeNull()
        ->and($row->signature_data)->toStartWith('data:image/png;base64,')
        ->and($row->tenant_id)->toBe($this->tenant->id);

    $this->getJson(AP_API.'/tickets/'.$ticket['id'])->assertOk()->assertJsonPath('data.status', 'WAIVER_SIGNED')->assertJsonPath('data.waiver_signed', true);
    $this->getJson(AP_API.'/public/waiver/'.$ticket['public_token'])->assertOk()->assertJsonPath('data.signed', true);
});

it('refuses a waiver whose people do not match the booking and a second signature', function (): void {
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1, 'JUNIOR' => 1])->assertCreated()->json('data');
    $url = AP_API.'/public/waiver/'.$ticket['public_token'].'/sign';

    $this->postJson($url, apSignPayload(0))->assertStatus(422)
        ->assertJsonPath('code', 'headcount_mismatch')->assertJsonPath('context.expected', 2)->assertJsonPath('context.given', 1);
    $this->postJson($url, apSignPayload(2))->assertStatus(422)->assertJsonPath('code', 'headcount_mismatch');
    expect(DB::table('adv_safety_waivers')->count())->toBe(0)->and(DB::table('adv_ticket_participants')->count())->toBe(0);

    $this->postJson($url, apSignPayload(1))->assertOk();
    $this->postJson($url, apSignPayload(1))->assertStatus(422)->assertJsonPath('code', 'waiver_already_signed');
});

it('validates the signature image, the acceptance of the rules and the measures of the visitors', function (): void {
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1])->assertCreated()->json('data');
    $url = AP_API.'/public/waiver/'.$ticket['public_token'].'/sign';

    $this->postJson($url, apSignPayload(0, ['signature_data' => 'not an image']))->assertStatus(422)->assertJsonValidationErrors(['signature_data']);
    $this->postJson($url, apSignPayload(0, ['accepted_rules' => false]))->assertStatus(422)->assertJsonValidationErrors(['accepted_rules']);
    $this->postJson($url, apSignPayload(0, ['locale' => 'es']))->assertStatus(422)->assertJsonValidationErrors(['locale']);

    $payload = apSignPayload(0);
    $payload['signer']['height_cm'] = 10;
    unset($payload['signer']['email']);
    $this->postJson($url, $payload)->assertStatus(422)->assertJsonValidationErrors(['signer.height_cm', 'signer.email']);
});

it('recommends the harness size from the height and the weight without signing in', function (int $height, int $weight, string $size): void {
    $this->getJson(AP_API.'/public/size-recommendation?height_cm='.$height.'&weight_kg='.$weight)->assertOk()->assertJsonPath('data.size', $size);
})->with([
    'toddler' => [100, 16, 'BABY'],
    'child' => [125, 30, 'JUNIOR'],
    'tall but light child' => [135, 44, 'JUNIOR'],
    'teenager heavier than the junior limit' => [135, 50, 'ADULT'],
    'adult' => [175, 70, 'ADULT'],
    'heavy adult' => [185, 110, 'XL'],
    'very tall adult' => [200, 85, 'XL'],
]);

it('validates the measures of the size recommendation', function (): void {
    $this->getJson(AP_API.'/public/size-recommendation?height_cm=5&weight_kg=70')->assertStatus(422)->assertJsonValidationErrors(['height_cm']);
    $this->getJson(AP_API.'/public/size-recommendation')->assertStatus(422)->assertJsonValidationErrors(['height_cm', 'weight_kg']);
});

it('lets the kiosk, which is signed in, use the same endpoints', function (): void {
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1])->assertCreated()->json('data');

    $this->getJson(AP_API.'/public/waiver/'.$ticket['booking_code'])->assertNotFound();
    $this->postJson(AP_API.'/public/waiver/'.$ticket['public_token'].'/sign', apSignPayload(0))->assertOk();
});

it('lists the lookups the screens need without labels', function (): void {
    $lookups = $this->getJson(AP_API.'/lookups')->assertOk()
        ->assertJsonPath('data.config.buffer_minutes', 15)
        ->assertJsonPath('data.config.teacher_free_ratio', 15)
        ->assertJsonPath('data.config.picnic_capacity', 60)
        ->assertJsonPath('data.waiver.version_id', 'v1')
        ->json('data');

    expect(collect($lookups['sizes'])->pluck('value')->all())->toBe(['BABY', 'JUNIOR', 'ADULT', 'XL'])
        ->and($lookups['ppe_categories'])->toContain('HARNESS', 'HELMET')
        ->and($lookups['ticket_statuses'])->toBe(['BOOKED', 'WAIVER_SIGNED', 'EQUIPPED', 'ON_COURSE', 'RETURNED', 'NO_SHOW'])
        ->and($lookups['wristband_colors'])->toContain('RED', 'WHITE')
        ->and(collect($lookups['workshops'])->pluck('code')->all())->toBe(['BEES', 'BREAD_GRAIN', 'GARDEN', 'JUNIOR_ADVENTURE'])
        ->and(collect($lookups['daily_check_items'])->pluck('key')->all())->toContain('cables_tension')
        ->and(collect($lookups['annual_check_items'])->pluck('key')->all())->toContain('webbing_wear')
        ->and($lookups['waiver']['locales'])->toBe(['it', 'en', 'de', 'fr']);
});
