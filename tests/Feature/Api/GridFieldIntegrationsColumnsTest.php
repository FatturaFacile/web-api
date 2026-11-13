<?php

declare(strict_types=1);

use App\Models\EcommerceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\DocumentSignature;
use Modules\Integrations\Models\ApiKey;
use Modules\ServiceManagement\Models\Ticket;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
});

/**
 * @return array<string, int>
 */
function gridValueCounts(string $uri): array
{
    return collect(test()->getJson($uri)->assertOk()->json('data'))->pluck('count', 'value')->all();
}

test('the ticket sla_state column is calculated in SQL: values, filter, sort and group', function (): void {
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open']);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'open', 'resolution_due_at' => now()->subHour()]);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'pending_customer']);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'closed', 'resolution_due_at' => now()->subDay(), 'resolved_at' => now()]);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'resolved', 'resolution_due_at' => now()->addDay(), 'resolved_at' => now()]);

    expect(gridValueCounts('/api/v1/esm/tickets?grid_values=sla_state'))->toBe(['breached' => 2, 'met' => 1, 'ok' => 1, 'paused' => 1]);

    $breached = $this->getJson('/api/v1/esm/tickets?filter[sla_state][]=breached&sort_by=sla_state&sort_dir=asc&grid_group_by=sla_state')->assertOk();
    expect($breached->json('meta.total'))->toBe(2)
        ->and($breached->json('meta.last_page'))->toBe(1)
        ->and($breached->json('meta.per_page'))->toBe(25);
});

test('the ticket grid sorts by the real columns behind the title and channel accessors', function (): void {
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'subject' => 'Bravo', 'source' => 'email']);
    Ticket::factory()->create(['tenant_id' => $this->tenant->id, 'subject' => 'Alfa', 'source' => 'portal']);

    expect($this->getJson('/api/v1/esm/tickets?sort_by=subject&sort_dir=asc')->assertOk()->json('data.0.title'))->toBe('Alfa')
        ->and(gridValueCounts('/api/v1/esm/tickets?grid_values=source'))->toBe(['email' => 1, 'portal' => 1]);
});

test('the api key status column tells revoked keys from active ones', function (): void {
    ApiKey::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Active key']);
    ApiKey::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Revoked key', 'revoked_at' => now()]);

    expect(gridValueCounts('/api/v1/integrations/api-keys?grid_values=status'))->toBe(['active' => 1, 'revoked' => 1]);

    $revoked = $this->getJson('/api/v1/integrations/api-keys?filter[status][]=revoked&per_page=5')->assertOk();
    expect($revoked->json('data'))->toHaveCount(1)
        ->and($revoked->json('data.0.name'))->toBe('Revoked key')
        ->and($revoked->json('meta.total'))->toBe(1)
        ->and($revoked->json('data.0'))->not->toHaveKey('key_hash');
});

test('the ecommerce channel status column is portable SQL over is_active', function (): void {
    foreach ([['Shop A', true], ['Shop B', false], ['Shop C', true]] as [$name, $active]) {
        EcommerceChannel::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'driver' => 'shopify', 'credentials' => ['token' => 'x'], 'is_active' => $active]);
    }

    expect(gridValueCounts('/api/v1/ecommerce/channels?grid_values=status'))->toBe(['active' => 2, 'inactive' => 1]);

    $inactive = $this->getJson('/api/v1/ecommerce/channels?filter[status][]=inactive')->assertOk();
    expect($inactive->json('data'))->toHaveCount(1)
        ->and($inactive->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/ecommerce/channels?sort_by=status&sort_dir=desc&per_page=1')->json('meta.total'))->toBe(3);
});

test('the document signature lookup is paginated and grid-aware, with flat data and a meta block', function (): void {
    $documentId = (string) Str::uuid();
    foreach (['cades-bes-p7m', 'sha256-integrity', 'sha256-integrity'] as $format) {
        DocumentSignature::factory()->create(['tenant_id' => $this->tenant->id, 'document_id' => $documentId, 'format' => $format]);
    }

    $page = $this->getJson("/api/v1/document-trust/signatures?document_id={$documentId}&per_page=2&sort_by=format&sort_dir=desc")->assertOk();
    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('data.0.format'))->toBe('sha256-integrity')
        ->and($page->json('meta.total'))->toBe(3)
        ->and($page->json('meta.last_page'))->toBe(2);

    expect(gridValueCounts("/api/v1/document-trust/signatures?document_id={$documentId}&grid_values=format"))->toBe(['cades-bes-p7m' => 1, 'sha256-integrity' => 2]);
});
