<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalDomainEventEnvelope;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Services\BiTemporalEventRecorder;

const TT_API = '/api/v1/time-travel';

/**
 * A company with a member of the given role, set as the acting user and active tenant.
 */
function ttMember(object $test, string $role = 'owner', string $name = 'Tempo'): User
{
    $tenant = Tenant::create(['name' => $name.' '.Str::random(5), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);
    $test->tenant = $tenant;
    $test->user = $user;

    return $user;
}

/**
 * Appends an event as the system knew it at $recordedAt (the clock is moved there, as the production code stamps the real time).
 *
 * @param  array<string, mixed>  $payload
 */
function ttRecord(object $test, string $tenantId, AggregateType $type, string $aggregateId, string $eventType, string $validAt, string $recordedAt, array $payload, ?string $actor = null): BiTemporalEvent
{
    $test->travelTo(CarbonImmutable::parse($recordedAt, 'UTC'));

    $event = app(BiTemporalEventRecorder::class)->record(new BiTemporalDomainEventEnvelope(
        $tenantId,
        $type,
        $aggregateId,
        $eventType,
        CarbonImmutable::parse($validAt, 'UTC'),
        $payload,
        ['source_event' => 'test'],
        $actor,
    ));

    $test->travelBack();

    return $event;
}

/**
 * A posted journal entry of the general ledger stream: $lines are [account, debit, credit].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $lines
 */
function ttJournal(object $test, string $tenantId, string $id, string $postingDate, string $recordedAt, array $lines, ?string $actor = null): BiTemporalEvent
{
    return ttRecord($test, $tenantId, AggregateType::GeneralLedger, $id, 'JournalEntryPosted', $postingDate, $recordedAt, [
        'journal_entry_id' => $id,
        'entry_number' => 'JE-'.$id,
        'posting_date' => $postingDate,
        'description' => 'Entry '.$id,
        'ledger_type' => 'statutory',
        'source_document_type' => 'manual',
        'source_document_id' => $id,
        'lines' => array_map(static fn (array $line): array => ['account_id' => $line[0], 'debit' => $line[1], 'credit' => $line[2]], $lines),
    ], $actor);
}

/**
 * An inventory movement with one leg: a receipt (positive quantity) or an issue (negative quantity) of an item in a warehouse.
 */
function ttStock(object $test, string $tenantId, string $movementId, string $validAt, string $recordedAt, string $item, string $warehouse, string $quantity, string $value, string $unitCost = '0'): BiTemporalEvent
{
    return ttRecord($test, $tenantId, AggregateType::InventoryWms, $movementId, 'InventoryLotMoved', $validAt, $recordedAt, [
        'movement_id' => $movementId,
        'item_id' => $item,
        'lot_id' => '',
        'unit_cost' => $unitCost,
        'legs' => [['warehouse_id' => $warehouse, 'quantity_delta' => $quantity, 'value_delta' => $value]],
    ]);
}
