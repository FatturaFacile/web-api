<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Events\TicketTimeBillable;
use Modules\ServiceManagement\Models\ContractHourTransaction;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Models\TicketTimeEntry;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->technician = ServiceTechnician::factory()->create(['tenant_id' => $this->tenant->id, 'hourly_billable_rate' => 80.00, 'hourly_cost_rate' => 40.00]);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'open', 'assigned_technician_id' => $this->technician->id]);

    $user = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * An active contract of the customer with a prepaid hour bank.
 *
 * @param  array<string, mixed>  $attributes
 */
function prepaidContract(object $test, string $included, string $consumed = '0.00', array $attributes = []): ServiceContract
{
    return ServiceContract::factory()->create([
        'tenant_id' => $test->tenant->id,
        'customer_id' => $test->customer->id,
        'included_hours' => $included,
        'consumed_hours' => $consumed,
        ...$attributes,
    ]);
}

test('time logged on a ticket is deducted from the prepaid hour bank of the customer contract', function (): void {
    Event::fake([TicketTimeBillable::class]);
    $contract = prepaidContract($this, '10.00');

    $entry = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 90, 'description' => 'Remote session'])
        ->assertCreated()
        ->assertJsonPath('data.hours', '1.50')
        ->assertJsonPath('data.minutes', 90)
        ->assertJsonPath('data.billing_status', 'contract_deducted')
        ->assertJsonPath('data.contract_hours', '1.50')
        ->assertJsonPath('data.billable_hours', '0.00')
        ->assertJsonPath('data.total_charge', '0.00')
        ->assertJsonPath('data.service_contract_id', $contract->id)
        ->json('data');

    expect($contract->fresh()->consumed_hours)->toBe('1.50')
        ->and($contract->fresh()->remainingHours())->toBe('8.50')
        ->and(ContractHourTransaction::query()->where('service_contract_id', $contract->id)->where('ticket_id', $this->ticket->id)->count())->toBe(1)
        ->and(TicketTimeEntry::query()->findOrFail($entry['id'])->contract_transaction_id)->not->toBeNull()
        ->and($this->ticket->fresh()->actual_resolution_minutes)->toBe(90)
        ->and(TicketActivity::query()->where('ticket_id', $this->ticket->id)->where('type', 'time_logged')->count())->toBe(1);

    Event::assertNotDispatched(TicketTimeBillable::class);
});

test('a bank that covers only part of the time is emptied and the rest is billable', function (): void {
    Event::fake([TicketTimeBillable::class]);
    $contract = prepaidContract($this, '10.00', '9.00');

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 90])
        ->assertCreated()
        ->assertJsonPath('data.billing_status', 'partially_deducted')
        ->assertJsonPath('data.contract_hours', '1.00')
        ->assertJsonPath('data.billable_hours', '0.50')
        ->assertJsonPath('data.total_charge', '40.00');

    expect($contract->fresh()->remainingHours())->toBe('0.00');

    Event::assertDispatched(TicketTimeBillable::class, fn (TicketTimeBillable $event): bool => $event->billableHours === '0.50'
        && $event->hourlyRate === '80.00'
        && $event->totalCharge === '40.00'
        && $event->customerId === $this->customer->id
        && $event->ticketId === $this->ticket->id
        && $event->tenantId === $this->tenant->id);
});

test('without a covering contract the time is billable time and materials', function (): void {
    Event::fake([TicketTimeBillable::class]);

    $entry = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 90])
        ->assertCreated()
        ->assertJsonPath('data.billing_status', 'billable_pending')
        ->assertJsonPath('data.contract_hours', '0.00')
        ->assertJsonPath('data.billable_hours', '1.50')
        ->assertJsonPath('data.total_charge', '120.00')
        ->assertJsonPath('data.service_contract_id', null)
        ->json('data');

    Event::assertDispatched(TicketTimeBillable::class, fn (TicketTimeBillable $event): bool => $event->timeEntryId === $entry['id'] && $event->billableHours === '1.50');
});

test('a depleted, expired, flat-rate, foreign or inactive contract does not cover the time', function (): void {
    Event::fake([TicketTimeBillable::class]);
    $depleted = prepaidContract($this, '5.00', '5.00');
    prepaidContract($this, '5.00', '0.00', ['end_date' => now()->subDay()->toDateString()]);
    prepaidContract($this, '5.00', '0.00', ['status' => 'suspended']);
    prepaidContract($this, '0.00');
    ServiceContract::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => Contact::factory()->create(['tenant_id' => $this->tenant->id])->id, 'included_hours' => '50.00']);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 60])
        ->assertCreated()->assertJsonPath('data.billing_status', 'billable_pending')->assertJsonPath('data.billable_hours', '1.00');

    expect($depleted->fresh()->consumed_hours)->toBe('5.00')
        ->and(ContractHourTransaction::query()->count())->toBe(0);
});

test('the contract named on the ticket covers the time before the customer contracts do', function (): void {
    $customerContract = prepaidContract($this, '10.00');
    $named = prepaidContract($this, '3.00');
    $this->ticket->update(['service_contract_id' => $named->id]);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 120])
        ->assertCreated()->assertJsonPath('data.service_contract_id', $named->id)->assertJsonPath('data.billing_status', 'contract_deducted');

    expect($named->fresh()->consumed_hours)->toBe('2.00')
        ->and($customerContract->fresh()->consumed_hours)->toBe('0.00');
});

test('non billable time touches neither the bank nor the billing', function (): void {
    Event::fake([TicketTimeBillable::class]);
    $contract = prepaidContract($this, '10.00');

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 30, 'is_billable' => false])
        ->assertCreated()->assertJsonPath('data.billing_status', 'non_billable')->assertJsonPath('data.contract_hours', '0.00')->assertJsonPath('data.billable_hours', '0.00');

    expect($contract->fresh()->consumed_hours)->toBe('0.00');
    Event::assertNotDispatched(TicketTimeBillable::class);
});

test('the hours of consecutive entries add up until the bank runs out', function (): void {
    Event::fake([TicketTimeBillable::class]);
    $contract = prepaidContract($this, '1.00');

    foreach ([30, 30, 30] as $minutes) {
        $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => $minutes])->assertCreated();
    }

    $entries = $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries")->assertOk()->assertJsonCount(3, 'data')->json('data');

    expect(collect($entries)->pluck('billing_status')->all())->toBe(['contract_deducted', 'contract_deducted', 'billable_pending'])
        ->and($contract->fresh()->remainingHours())->toBe('0.00')
        ->and($this->ticket->fresh()->actual_resolution_minutes)->toBe(90);
});

test('time needs a valid duration and a technician of the tenant', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 1441])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", [])->assertUnprocessable()->assertJsonValidationErrors('minutes');

    $foreign = ServiceTechnician::factory()->create(['tenant_id' => $this->other->id]);
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/time-entries", ['minutes' => 10, 'technician_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('technician_id');

    $unassigned = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'open', 'assigned_technician_id' => null]);
    $this->postJson("/api/v1/esm/tickets/{$unassigned->id}/time-entries", ['minutes' => 10])->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$unassigned->id}/time-entries", ['minutes' => 10, 'technician_id' => $this->technician->id])->assertCreated();

    expect(TicketTimeEntry::query()->count())->toBe(1);
});

test('time cannot be logged on a locked ticket or a ticket of another tenant', function (): void {
    $locked = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'is_locked' => true, 'assigned_technician_id' => $this->technician->id]);
    $foreign = Ticket::factory()->create(['tenant_id' => $this->other->id]);

    $this->postJson("/api/v1/esm/tickets/{$locked->id}/time-entries", ['minutes' => 10])->assertForbidden();
    $this->postJson("/api/v1/esm/tickets/{$foreign->id}/time-entries", ['minutes' => 10])->assertStatus(404);
    $this->getJson("/api/v1/esm/tickets/{$foreign->id}/time-entries")->assertStatus(404);

    expect(TicketTimeEntry::query()->count())->toBe(0);
});

test('a replay with the same idempotency key returns the first entry and books the hours once', function (): void {
    $contract = prepaidContract($this, '10.00');
    $url = "/api/v1/esm/tickets/{$this->ticket->id}/time-entries";

    $first = $this->postJson($url, ['minutes' => 60, 'idempotency_key' => 'queue-1'])->assertCreated()->json('data.id');
    $again = $this->withHeader('Idempotency-Key', 'queue-1')->postJson($url, ['minutes' => 60])->assertOk()->json('data.id');
    $other = $this->postJson($url, ['minutes' => 60, 'idempotency_key' => 'queue-2'])->assertCreated()->json('data.id');

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first)
        ->and(TicketTimeEntry::query()->count())->toBe(2)
        ->and($contract->fresh()->consumed_hours)->toBe('2.00');
});
