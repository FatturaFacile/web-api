<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\ServiceManagement\Models\WorkOrder;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(9, 0));

    $this->tenant = Tenant::factory()->create(['country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
    Sanctum::actingAs($this->user);

    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'country' => 'CH', 'company_name' => 'ACME SA', 'vat_number' => null]);
    $this->team = ServiceTeam::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->ticket = Ticket::factory()->create([
        'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'open', 'priority' => 'high',
        'subject' => 'Pump does not start', 'description' => 'The pump of line 3 stays off.', 'assigned_team_id' => $this->team->id,
    ]);

    $this->registerPassport = fn (string $serial): array => $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => $serial,
        'current_owner_customer_id' => $this->customer->id,
        'delivery_date' => '2025-03-10',
        'components' => [['position' => 'PSU', 'component_serial' => 'PSU-'.$serial, 'role' => 'power_supply']],
    ])->assertCreated()->json('data');
});

/**
 * A public customer reply and an internal agent note on the ticket.
 */
function conversation(object $test): void
{
    $test->postJson("/api/v1/esm/tickets/{$test->ticket->id}/messages", ['body' => 'The breaker trips when we start it', 'visibility' => 'public'])->assertCreated();
    $test->postJson("/api/v1/esm/tickets/{$test->ticket->id}/messages", ['body' => 'Probably the motor winding', 'visibility' => 'internal'])->assertCreated();
}

test('one click turns the ticket into a field service work order with the whole conversation', function (): void {
    conversation($this);

    $response = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/field-service", ['work_type' => 'emergency'])
        ->assertCreated()
        ->assertJsonPath('data.ticket_id', $this->ticket->id)
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.title', 'Pump does not start')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.work_type', 'emergency')
        ->assertJsonPath('data.service_team_id', $this->team->id)
        ->assertJsonPath('data.billing_type', 'time_and_materials');

    $description = (string) $response->json('data.description');
    expect($description)->toContain('The pump of line 3 stays off.')
        ->toContain('PUBLIC): The breaker trips when we start it')
        ->toContain('INTERNAL NOTE): Probably the motor winding')
        ->and(WorkOrder::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1);

    $ticket = $this->ticket->fresh();
    expect($ticket->metadata['handoffs'][0]['kind'])->toBe('field_service')
        ->and($ticket->metadata['handoffs'][0]['id'])->toBe($response->json('data.id'))
        ->and(TicketActivity::query()->where('ticket_id', $ticket->id)->where('type', 'handed_off')->count())->toBe(1)
        ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->where('visibility', 'internal')->where('body', 'like', 'Handed off to field_service: WO-%')->count())->toBe(1);
});

test('the work order carries the contract and the equipment of the ticket', function (): void {
    $contract = ServiceContract::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id]);
    $passport = ($this->registerPassport)('SN-HANDOFF-WO');
    $this->ticket->update(['service_contract_id' => $contract->id, 'metadata' => ['equipment_passport_id' => $passport['id']]]);

    $id = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/field-service")
        ->assertCreated()->assertJsonPath('data.service_contract_id', $contract->id)->assertJsonPath('data.billing_type', 'contract_included')->json('data.id');

    expect(WorkOrder::query()->findOrFail($id)->getAttribute('equipment_passport_id'))->toBe($passport['id']);
});

test('a second click returns the same work order instead of opening another', function (): void {
    $first = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/field-service")->assertCreated()->json('data.id');
    $second = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/field-service")->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(WorkOrder::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1)
        ->and(TicketActivity::query()->where('ticket_id', $this->ticket->id)->where('type', 'handed_off')->count())->toBe(1);
});

test('one click turns the ticket into an RMA return linked to the ticket and the unit', function (): void {
    conversation($this);
    $passport = ($this->registerPassport)('SN-HANDOFF-1');

    $response = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma", ['serial_number' => 'SN-HANDOFF-1'])
        ->assertCreated()
        ->assertJsonPath('data.serial_number', 'SN-HANDOFF-1')
        ->assertJsonPath('data.equipment_passport_id', $passport['id'])
        ->assertJsonPath('data.customer_id', $this->customer->id);

    $rma = DepotRepairRmaOrder::query()->where('ticket_id', $this->ticket->id)->sole();
    expect($response->json('meta.tracking_token'))->toBeString()->not->toBe('')
        ->and($rma->id)->toBe($response->json('data.id'))
        ->and((string) $rma->reported_symptom)->toContain('The pump of line 3 stays off.')->toContain('INTERNAL NOTE): Probably the motor winding')
        ->and($this->ticket->fresh()->metadata['handoffs'][0]['kind'])->toBe('rma')
        ->and($this->ticket->fresh()->metadata['handoffs'][0]['reference'])->toBe($rma->rma_number);

    // The same ticket does not issue a second RMA; the tracking token is shown once only.
    $again = $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma", ['serial_number' => 'SN-HANDOFF-1'])->assertOk();
    expect($again->json('data.id'))->toBe($rma->id)
        ->and($again->json('meta.tracking_token'))->toBeNull()
        ->and(DepotRepairRmaOrder::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1);
});

test('the RMA unit comes from the equipment passport linked to the ticket, or the request must name it', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma")->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma", ['serial_number' => 'SN-UNKNOWN'])->assertUnprocessable();
    expect(DepotRepairRmaOrder::query()->count())->toBe(0);

    $passport = ($this->registerPassport)('SN-LINKED');
    $this->ticket->update(['metadata' => ['equipment_passport_id' => $passport['id']]]);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma", ['goodwill' => true, 'reported_symptom' => 'Does not boot'])
        ->assertCreated()->assertJsonPath('data.serial_number', 'SN-LINKED');
});

test('only an open ticket of the tenant can be handed off, and only by someone who may escalate it', function (): void {
    $closed = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'closed']);
    $merged = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'merged_into_ticket_id' => $this->ticket->id]);
    $locked = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'is_locked' => true]);
    $foreign = app(TenantContext::class)->runWithTenant($this->other, fn () => Ticket::factory()->create(['tenant_id' => $this->other->id, 'status' => 'open']));

    $this->postJson("/api/v1/esm/tickets/{$closed->id}/escalate/field-service")->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$merged->id}/escalate/field-service")->assertUnprocessable();
    $this->postJson("/api/v1/esm/tickets/{$locked->id}/escalate/field-service")->assertForbidden();
    $this->postJson("/api/v1/esm/tickets/{$foreign->id}/escalate/field-service")->assertNotFound();
    $this->postJson("/api/v1/esm/tickets/{$foreign->id}/escalate/rma", ['serial_number' => 'X'])->assertNotFound();

    $otherPassport = SerializedEquipmentPassport::factory()->create(['tenant_id' => $this->other->id]);
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/escalate/rma", ['equipment_passport_id' => $otherPassport->id])->assertUnprocessable()->assertJsonValidationErrors('equipment_passport_id');

    expect(WorkOrder::query()->count())->toBe(0)
        ->and(DepotRepairRmaOrder::query()->count())->toBe(0);
});
