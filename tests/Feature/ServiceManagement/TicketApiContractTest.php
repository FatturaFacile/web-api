<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\Ticket;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $user = User::create(['name' => 'Admin', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('a ticket refuses a customer, category, contract or team of another company', function (): void {
    $foreignContact = Contact::factory()->create(['tenant_id' => $this->other->id]);

    $this->postJson('/api/v1/esm/tickets', [
        'title' => 'Stampante guasta',
        'customer_id' => $foreignContact->id,
        'service_category_id' => (string) Str::uuid(),
        'service_contract_id' => (string) Str::uuid(),
        'service_team_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'service_category_id', 'service_contract_id', 'service_team_id']);

    expect(Ticket::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an internal ticket has no customer, and the resource shows the stored columns with the earlier keys', function (): void {
    $data = $this->postJson('/api/v1/esm/tickets', [
        'title' => 'Rinnovo certificati interni',
        'channel' => 'email',
        'contact_email' => 'it@example.com',
        'contact_phone' => '+41 91 000 00 00',
    ])->assertCreated()->json('data');

    expect($data['customer_id'])->toBeNull()
        ->and(Ticket::query()->withoutGlobalScopes()->findOrFail($data['id'])->customer_id)->toBeNull()
        ->and($data['subject'])->toBe('Rinnovo certificati interni')
        ->and($data['title'])->toBe('Rinnovo certificati interni')
        ->and($data['source'])->toBe('email')
        ->and($data['channel'])->toBe('email')
        ->and($data['contact_email'])->toBe('it@example.com')
        ->and($data['contact_phone'])->toBe('+41 91 000 00 00')
        ->and($data)->toHaveKeys(['category_id', 'service_category_id', 'assigned_technician_id', 'assigned_to_id', 'assigned_team_id', 'service_team_id', 'contact_id', 'type']);
});

test('a ticket for an own customer keeps it', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson('/api/v1/esm/tickets', ['title' => 'Assistenza', 'customer_id' => $contact->id])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $contact->id);
});
