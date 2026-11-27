<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Application\Services\ParticipantDirectoryService;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Infrastructure\Gateways\HttpEbillGateway;
use Modules\SwissEbill\Infrastructure\Gateways\SandboxEbillGateway;
use Modules\SwissEbill\Infrastructure\Jobs\BulkVerifyCustomerEbillJob;
use Modules\SwissEbill\Models\EbillParticipantLookup;
use Modules\SwissEbill\Models\EbillSetting;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => ebSetUp($this));

/**
 * A customer that knows nothing about eBill yet.
 *
 * @param  array<string, mixed>  $overrides
 */
function ebUnverified(array $overrides = []): Contact
{
    return ebCustomer(array_merge(['ebill_recipient_id' => null, 'ebill_status' => 'unknown', 'ebill_channel_type' => null], $overrides));
}

test('a lookup by UID finds an enrolled B2B participant and an even one is not enrolled', function (): void {
    $odd = null;
    $even = null;

    for ($seed = 10000200; $odd === null || $even === null; $seed += 50) {
        $uid = ebUid($seed);

        if (((int) substr((string) preg_replace('/\D/', '', $uid), -1)) % 2 === 1) {
            $odd ??= $uid;
        } else {
            $even ??= $uid;
        }
    }

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'uid', 'lookup_value' => $odd])->assertOk()
        ->assertJsonPath('data.status', 'enrolled')->assertJsonPath('data.channel_type', 'b2b_enterprise')->assertJsonPath('data.uid', $odd);
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'uid', 'lookup_value' => $even])->assertOk()->assertJsonPath('data', null);
});

test('a lookup by email, IBAN and participant id goes through the directory', function (): void {
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'email', 'lookup_value' => 'mario.ebill@example.com'])
        ->assertOk()->assertJsonPath('data.status', 'enrolled')->assertJsonPath('data.channel_type', 'b2c_private');

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'iban', 'lookup_value' => 'CH93 0076 2011 6238 5295 7'])
        ->assertOk()->assertJsonPath('data.status', 'enrolled');

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'participant_id', 'lookup_value' => ebBillerId()])
        ->assertOk()->assertJsonPath('data.participant_id', ebBillerId());

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'email', 'lookup_value' => 'nobody@example.com'])
        ->assertOk()->assertJsonPath('data', null);
});

test('invalid identifiers are refused before the directory is asked', function (): void {
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'uid', 'lookup_value' => 'CHE-123.456.789'])->assertStatus(422);
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'email', 'lookup_value' => 'not-an-email'])->assertStatus(422);
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'iban', 'lookup_value' => '12'])->assertStatus(422);
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'phone', 'lookup_value' => '+4179'])->assertStatus(422);
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'email'])->assertStatus(422);
});

test('answers are cached and a forced refresh asks the gateway again', function (): void {
    $calls = new ArrayObject(['n' => 0]);
    $counting = new class($calls) extends SandboxEbillGateway
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function lookupParticipant(EbillSetting $setting, ParticipantLookupCriteria $criteria): ?ParticipantRecord
        {
            $this->calls['n']++;

            return parent::lookupParticipant($setting, $criteria);
        }
    };
    $this->app->instance(EbillGatewayFactory::class, new EbillGatewayFactory($counting, app(HttpEbillGateway::class)));

    $payload = ['lookup_type' => 'email', 'lookup_value' => 'mario.ebill@example.com'];
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', $payload)->assertOk();
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', $payload)->assertOk();
    expect($calls['n'])->toBe(1)->and(EbillParticipantLookup::query()->count())->toBe(1);

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', $payload + ['force_refresh' => true])->assertOk();
    expect($calls['n'])->toBe(2);

    $this->travelTo(now()->addHours(25));
    $this->postJson('/api/v1/swiss-ebill/participants/lookup', $payload)->assertOk();
    expect($calls['n'])->toBe(3);
});

test('a lookup needs eBill to be enabled', function (): void {
    $this->putJson('/api/v1/swiss-ebill/settings', ['ebill_enabled' => false])->assertOk();

    $this->postJson('/api/v1/swiss-ebill/participants/lookup', ['lookup_type' => 'email', 'lookup_value' => 'mario.ebill@example.com'])
        ->assertStatus(422)->assertJsonPath('code', 'ebill_disabled');
});

test('syncing a customer stores the enrollment found in the directory', function (): void {
    $customer = ebUnverified(['email' => 'mario.ebill@example.com', 'uid_number' => null]);

    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/sync')->assertOk()
        ->assertJsonPath('data.customer.ebill_status', 'enrolled')
        ->assertJsonPath('data.customer.ebill_channel_type', 'b2c_private')
        ->assertJsonPath('data.participant.status', 'enrolled');

    $customer->refresh();

    expect($customer->ebill_status)->toBe('enrolled')
        ->and($customer->ebill_recipient_id)->toBe(ebParticipant('mario.ebill@example.com'))
        ->and($customer->ebill_verified_at)->not->toBeNull();
});

test('a customer the directory does not know becomes not enrolled', function (): void {
    $customer = ebUnverified(['email' => 'nobody@example.com', 'uid_number' => null]);

    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/sync')->assertOk()
        ->assertJsonPath('data.customer.ebill_status', 'not_enrolled')
        ->assertJsonPath('data.customer.ebill_recipient_id', null)
        ->assertJsonPath('data.participant', null);

    expect($customer->refresh()->ebill_status)->toBe('not_enrolled')->and($customer->ebill_verified_at)->not->toBeNull();
});

test('pending opt-in and suspended customers cannot receive', function (string $email, string $status): void {
    $customer = ebUnverified(['email' => $email, 'uid_number' => null]);

    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/sync')->assertOk()->assertJsonPath('data.customer.ebill_status', $status);
})->with([
    ['anna.optin@example.com', 'pending_opt_in'],
    ['paul.suspended@example.com', 'suspended'],
]);

test('a customer of another company cannot be synced', function (): void {
    app(TenantContext::class)->setTenant($this->otherTenant);
    $foreign = ebUnverified();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->postJson('/api/v1/swiss-ebill/customers/'.$foreign->id.'/ebill/sync')->assertNotFound();
});

test('linking stores a participant the directory really knows', function (): void {
    $customer = ebUnverified();

    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/link', ['participant_id' => ebBillerId()])->assertOk()
        ->assertJsonPath('data.customer.ebill_recipient_id', ebBillerId())
        ->assertJsonPath('data.customer.ebill_status', 'enrolled');

    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/link', ['participant_id' => '123'])->assertStatus(404)->assertJsonPath('code', 'participant_not_found');
    $this->postJson('/api/v1/swiss-ebill/customers/'.$customer->id.'/ebill/link', [])->assertStatus(422);
});

test('the bulk verification runs in the background for the selected customers', function (): void {
    Queue::fake();
    $first = ebUnverified();
    $second = ebUnverified();

    $this->postJson('/api/v1/swiss-ebill/customers/ebill/bulk-verify', ['customer_ids' => [$first->id, $second->id]])->assertStatus(202)
        ->assertJsonPath('data.queued_customers', 2)->assertJsonPath('data.all_customers', false);

    Queue::assertPushed(BulkVerifyCustomerEbillJob::class, fn (BulkVerifyCustomerEbillJob $job): bool => $job->customerIds === [$first->id, $second->id] && $job->tenantId === $this->tenant->id);

    $this->postJson('/api/v1/swiss-ebill/customers/ebill/bulk-verify', [])->assertStatus(202)->assertJsonPath('data.all_customers', true);
    $this->postJson('/api/v1/swiss-ebill/customers/ebill/bulk-verify', ['customer_ids' => ['not-a-uuid']])->assertStatus(422);
});

test('the bulk job verifies every customer and survives one failure', function (): void {
    $enrolled = ebUnverified(['email' => 'mario.ebill@example.com', 'uid_number' => null]);
    $unknown = ebUnverified(['email' => 'nobody@example.com', 'uid_number' => null]);
    $supplier = ebUnverified(['type' => 'supplier', 'email' => 'supplier.ebill@example.com', 'uid_number' => null]);

    (new BulkVerifyCustomerEbillJob($this->tenant->id))->handle(app(TenantContext::class), app(ParticipantDirectoryService::class));

    expect($enrolled->refresh()->ebill_status)->toBe('enrolled')
        ->and($unknown->refresh()->ebill_status)->toBe('not_enrolled')
        ->and($supplier->refresh()->ebill_status)->toBe('unknown');
});
