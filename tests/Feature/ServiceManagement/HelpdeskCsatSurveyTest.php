<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Mail\HelpdeskTicketMail;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\Ticket;

uses(RefreshDatabase::class);

/**
 * The plain token of the survey link in the last notice mailed for the ticket.
 */
function helpdeskSurveyToken(Ticket $ticket): string
{
    $mail = Mail::sent(HelpdeskTicketMail::class, fn (HelpdeskTicketMail $mail): bool => $mail->ticketNumber === $ticket->ticket_number && $mail->isAutomatic && str_contains($mail->body, '/portal/csat/'))->last();

    expect($mail)->not->toBeNull();
    preg_match('#/portal/csat/([a-f0-9]{64})#', $mail->body, $match);

    return $match[1];
}

beforeEach(function (): void {
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->foreignTicket = Ticket::factory()->create(['tenant_id' => $this->other->id]);
    $this->ticket = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'customer_id' => null, 'metadata' => ['contact_email' => 'anna@customer.test']]);
    $user = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('resolving a ticket sends one survey whose link records the answer once', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk();

    $survey = CSATSurvey::query()->where('ticket_id', $this->ticket->id)->sole();
    expect($survey->status)->toBe('pending')->and($survey->score)->toBeNull()->and($survey->respondent_email)->toBe('anna@customer.test');

    $token = helpdeskSurveyToken($this->ticket);
    expect($survey->token_hash)->toBe(hash('sha256', $token));

    $this->getJson("/api/v1/portal/csat/{$token}")->assertOk()->assertJsonPath('data.can_answer', true)->assertJsonPath('data.ticket_number', $this->ticket->ticket_number);
    $this->postJson("/api/v1/portal/csat/{$token}", ['score' => 2, 'feedback' => 'Slow'])->assertOk()->assertJsonPath('data.score', 2)->assertJsonPath('data.status', 'completed');
    $this->postJson("/api/v1/portal/csat/{$token}", ['score' => 5])->assertUnprocessable();

    expect($survey->fresh()->sentiment)->toBe('negative')
        ->and($this->ticket->fresh()->csat_score)->toBe(2);

    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/reopen")->assertOk();
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk();
    expect(CSATSurvey::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1);
});

test('the CSAT endpoint completes the pending survey of the ticket instead of adding one', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/resolve", ['resolution_notes' => 'Fixed'])->assertOk();

    $this->postJson('/api/v1/esm/csat', ['score' => 4, 'ticket_id' => $this->ticket->id])->assertCreated()->assertJsonPath('data.status', 'completed');

    expect(CSATSurvey::query()->where('ticket_id', $this->ticket->id)->count())->toBe(1)
        ->and(CSATSurvey::query()->where('ticket_id', $this->ticket->id)->value('score'))->toBe(4);

    $this->postJson('/api/v1/esm/csat', ['score' => 4, 'ticket_id' => $this->foreignTicket->id])->assertUnprocessable()->assertJsonValidationErrors('ticket_id');
});

test('expired and unknown links are refused, and no survey goes out when surveys are off or nobody can receive it', function (): void {
    $this->postJson("/api/v1/esm/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk();
    $token = helpdeskSurveyToken($this->ticket);

    $this->travel(31)->days();
    $this->postJson("/api/v1/portal/csat/{$token}", ['score' => 5])->assertUnprocessable();
    $this->getJson('/api/v1/portal/csat/'.str_repeat('a', 64))->assertNotFound();

    HelpdeskSetting::factory()->create(['tenant_id' => $this->tenant->id, 'csat_enabled' => false]);
    $quiet = Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'metadata' => ['contact_email' => 'x@customer.test']]);
    $this->postJson("/api/v1/esm/tickets/{$quiet->id}/status", ['status' => 'resolved'])->assertOk();

    expect(CSATSurvey::query()->where('ticket_id', $quiet->id)->exists())->toBeFalse();
});
