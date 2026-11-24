<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Ticket;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

test('an employee opens an internal IT ticket with the colleague and the device concerned', function (): void {
    $tenant = verticalTenant();
    $employee = verticalMember($tenant, 'member');
    verticalApi($employee, $tenant);

    $response = $this->postJson('/api/v1/esm/tickets', ['title' => 'Stampante bloccata', 'description' => 'La stampante del secondo piano non stampa', 'channel' => 'portal', 'requester_user_id' => $employee->id])
        ->assertCreated();

    expect(Ticket::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'))->requester_user_id)->toBe((string) $employee->id);

    $this->postJson('/api/v1/esm/tickets', ['title' => 'x', 'asset_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('asset_id');
});

test('a requester or an asset of another company is refused', function (): void {
    $tenant = verticalTenant();
    $stranger = verticalMember(verticalTenant(), 'admin');
    verticalApi(verticalMember($tenant, 'admin'), $tenant);

    $this->postJson('/api/v1/esm/tickets', ['title' => 'VPN', 'requester_user_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors('requester_user_id');
});
