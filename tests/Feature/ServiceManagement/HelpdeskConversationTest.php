<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketAttachment;
use Modules\ServiceManagement\Models\TicketMessage;

uses(RefreshDatabase::class);

function helpdeskConversationMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => 'Agent '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

beforeEach(function (): void {
    Storage::fake('private');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'client@example.com']);
    $this->foreignTicket = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'new', 'customer_id' => $this->customer->id, 'first_responded_at' => null]);
    $this->portalToken = app(PortalTokenService::class)->createToken($this->customer)['token'];
    $this->agent = helpdeskConversationMember($this->tenant);
    Sanctum::actingAs($this->agent);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('the first public reply records the first response and opens a new ticket, an internal note does not', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Checking logs', 'visibility' => 'internal'])
        ->assertCreated()->assertJsonPath('data.visibility', 'internal');

    expect($this->ticket->fresh()->first_responded_at)->toBeNull()
        ->and($this->ticket->fresh()->status)->toBe('new');

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'We are on it', 'status' => 'pending_customer'])
        ->assertCreated()->assertJsonPath('data.author_type', 'staff');

    $ticket = $this->ticket->fresh();
    expect($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->status)->toBe('pending_customer')
        ->and($ticket->sla_paused_at)->not->toBeNull();
});

test('attachments go to the private disk and are served only through the ticket', function (): void {
    $file = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf');

    $message = $this->post("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'See attached', 'attachments' => [$file]], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    $attachment = TicketAttachment::query()->findOrFail($message['attachments'][0]['id']);
    Storage::disk('private')->assertExists($attachment->path);
    expect($attachment->path)->toStartWith("tenants/{$this->tenant->id}/helpdesk/{$this->ticket->id}/")
        ->and($attachment->original_name)->toBe('report.pdf');

    $this->get("/api/v1/esm/tickets/{$this->ticket->id}/attachments/{$attachment->id}")->assertOk();
    $other = Ticket::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->getJson("/api/v1/esm/tickets/{$other->id}/attachments/{$attachment->id}")->assertNotFound();

    Sanctum::actingAs(helpdeskConversationMember($this->other));
    $this->withHeader('X-Tenant-Id', $this->other->id)
        ->getJson("/api/v1/esm/tickets/{$this->ticket->id}/attachments/{$attachment->id}")->assertNotFound();
});

test('the customer sees only public replies on the portal and the portal reply joins the same thread', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Internal: customer is VIP', 'visibility' => 'internal'])->assertCreated();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Can you send a photo?', 'status' => 'pending_customer'])->assertCreated();

    $portal = $this->getJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$this->ticket->id}")->assertOk()->json('data');
    expect(collect($portal['messages'])->pluck('body')->all())->toBe(['Can you send a photo?'])
        ->and($portal['messages'][0])->not->toHaveKey('author_email');

    $this->post("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$this->ticket->id}/reply", ['message' => 'Here it is', 'attachments' => [UploadedFile::fake()->image('photo.jpg')]], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.author_type', 'customer');

    expect($this->ticket->fresh()->status)->toBe('open');

    $this->postJson("/api/v1/portal/tickets/{$this->portalToken}/reply/{$this->ticket->id}", ['message' => 'Any news?'])->assertCreated();

    $thread = $this->getJson("/api/v1/esm/tickets/{$this->ticket->id}/messages")->assertOk()->json('data');
    expect(collect($thread)->pluck('body')->all())->toBe(['Internal: customer is VIP', 'Can you send a photo?', 'Here it is', 'Any news?']);

    $this->getJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets")->assertOk()->assertJsonCount(1, 'data');
});

test('the portal never serves the ticket of another customer nor an internal attachment', function (): void {
    $otherCustomer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTicket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $otherCustomer->id]);
    $this->getJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$otherTicket->id}")->assertNotFound();
    $this->getJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$this->foreignTicket->id}")->assertNotFound();

    $note = $this->post("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Internal log', 'visibility' => 'internal', 'attachments' => [UploadedFile::fake()->create('log.txt', 1, 'text/plain')]], ['Accept' => 'application/json'])->json('data');

    $this->get("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$this->ticket->id}/attachments/{$note['attachments'][0]['id']}")->assertNotFound();
});

test('a customer cannot reply to a ticket closed beyond the reopen window, and one within it reopens', function (): void {
    $this->travelTo('2027-05-01 10:00:00');
    $this->portalToken = app(PortalTokenService::class)->createToken($this->customer)['token'];
    $recent = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'resolved', 'resolved_at' => now()->subDay()]);
    $old = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'closed', 'resolved_at' => now()->subDays(30), 'closed_at' => now()->subDays(29)]);

    $this->postJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$recent->id}/reply", ['message' => 'Still broken'])->assertCreated();
    expect($recent->fresh()->status)->toBe('open')->and($recent->fresh()->reopened_count)->toBe(1);

    $this->postJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$old->id}/reply", ['message' => 'Again'])->assertUnprocessable();
    expect(TicketMessage::query()->where('ticket_id', $old->id)->count())->toBe(0);
});

test('a customer opens a ticket on the portal and the unread count follows the read state', function (): void {
    $created = $this->postJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets", ['subject' => 'Invoice question', 'description' => 'Which VAT rate?'])
        ->assertCreated()->assertJsonPath('data.status', 'new')->json('data');

    $ticket = Ticket::query()->findOrFail($created['id']);
    expect($ticket->customer_id)->toBe($this->customer->id)->and($ticket->source)->toBe('portal');

    $this->postJson("/api/v1/portal/helpdesk/{$this->portalToken}/tickets/{$ticket->id}/reply", ['message' => 'One more detail'])->assertCreated();
    $this->getJson("/api/v1/esm/tickets/{$ticket->id}/messages")->assertOk()->assertJsonPath('meta.unread', 1);
    $this->postJson("/api/v1/esm/tickets/{$ticket->id}/read")->assertOk();
    $this->getJson("/api/v1/esm/tickets/{$ticket->id}/messages")->assertOk()->assertJsonPath('meta.unread', 0);
});

test('the portal ticket list shows the helpdesk tickets of the customer with public messages only', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'We are on it', 'visibility' => 'public'])->assertCreated();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/messages", ['body' => 'Internal: escalate', 'visibility' => 'internal'])->assertCreated();
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => Contact::factory()->create(['tenant_id' => $this->tenant->id])->id]);

    $list = $this->getJson("/api/v1/portal/tickets/{$this->portalToken}")->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.id'))->toBe($this->ticket->id)
        ->and($list->json('data.0.ticket_number'))->toBe($this->ticket->ticket_number)
        ->and(collect($list->json('data.0.messages'))->pluck('message')->all())->toBe(['We are on it'])
        ->and($list->json('data.0.attachments'))->toBe([])
        ->and($list->json('meta.total'))->toBe(1);
});
