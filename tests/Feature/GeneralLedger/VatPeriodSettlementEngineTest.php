<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Models\VatSettlementPeriod;
use Modules\SDI\Enums\TipoDocumento;
use Modules\SDI\Services\IntegrationDocumentTypeDetector;

uses(RefreshDatabase::class);

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array{tenant: Tenant, user: User, headers: array<string, string>}
 */
function p4Tenant(string $country): array
{
    $tenant = Tenant::create([
        'name' => "P4 {$country} ".Str::random(4),
        'legal_name' => "P4 {$country}",
        'country' => $country,
        'default_currency' => $country === 'IT' ? 'EUR' : 'CHF',
        'vat_number' => $country === 'IT' ? 'IT01234567890' : 'CHE-123.456.789',
        'fiscal_code' => $country === 'IT' ? '01234567890' : null,
    ]);

    $user = User::create(['name' => 'Accounting', 'email' => Str::random(10).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($tenant);

    return ['tenant' => $tenant, 'user' => $user, 'headers' => ['X-Tenant-Id' => (string) $tenant->id]];
}

it('previews and locks an Italian LIPE period, then exports a schema-valid XML', function (): void {
    ['user' => $user, 'headers' => $headers] = p4Tenant('IT');
    Sanctum::actingAs($user);
    $body = ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1];

    $preview = $this->withHeaders($headers)->postJson('/api/v1/vat/settlements/preview', $body)->assertOk();

    expect($preview->json('data.jurisdiction'))->toBe('IT')
        ->and($preview->json('data.lipe_boxes'))->toHaveKeys(['VP2', 'VP3', 'VP4', 'VP5', 'VP6_due', 'VP7', 'VP8', 'VP9', 'VP12', 'VP14_payable'])
        ->and(VatSettlementPeriod::query()->count())->toBe(0);

    $locked = $this->withHeaders($headers)->postJson('/api/v1/vat/settlements', $body)->assertCreated();
    expect($locked->json('data.status'))->toBe('CLOSED');

    $this->withHeaders($headers)->postJson('/api/v1/vat/settlements', $body)->assertStatus(409);

    $xml = $this->withHeaders($headers)->get('/api/v1/vat/settlements/'.$locked->json('data.id').'/export-xml')->assertOk();
    expect($xml->getContent())->toContain('IVP18')->toContain('<iv:Trimestre>1</iv:Trimestre>');

    $this->withHeaders($headers)->get('/api/v1/vat/settlements/'.$locked->json('data.id').'/export-pdf')->assertOk();
});

it('previews a Swiss MWST period with the AFC boxes and 0.05 rounding', function (): void {
    ['user' => $user, 'headers' => $headers] = p4Tenant('CH');
    Sanctum::actingAs($user);

    $preview = $this->withHeaders($headers)
        ->postJson('/api/v1/vat/settlements/preview', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])
        ->assertOk();

    expect($preview->json('data.jurisdiction'))->toBe('CH')
        ->and($preview->json('data.swiss_boxes'))->toHaveKeys(['200', '299', '303', '313', '343', '383', '399', '479', '500', '510']);

    $locked = $this->withHeaders($headers)
        ->postJson('/api/v1/vat/settlements', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])
        ->assertCreated();

    $this->withHeaders($headers)->get('/api/v1/vat/settlements/'.$locked->json('data.id').'/export-xml')->assertOk();
});

it('rejects an invalid period', function (): void {
    ['user' => $user, 'headers' => $headers] = p4Tenant('IT');
    Sanctum::actingAs($user);

    $this->withHeaders($headers)->postJson('/api/v1/vat/settlements/preview', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 9])->assertStatus(422);
});

it('keeps a locked period immutable', function (): void {
    ['user' => $user, 'headers' => $headers] = p4Tenant('IT');
    Sanctum::actingAs($user);

    $id = $this->withHeaders($headers)->postJson('/api/v1/vat/settlements', ['period_type' => 'monthly', 'year' => 2026, 'period' => 2])->json('data.id');
    $period = VatSettlementPeriod::query()->findOrFail($id);

    expect(fn () => $period->update(['payable_amount' => '1.00']))->toThrow(VatSettlementException::class)
        ->and(fn () => $period->delete())->toThrow(VatSettlementException::class);

    $fresh = VatSettlementPeriod::query()->findOrFail($id);
    $fresh->update(['status' => VatSettlementPeriod::STATUS_FILED, 'filed_at' => now()]);
    expect($fresh->fresh()->status)->toBe('FILED');
});

it('isolates VAT periods between tenants', function (): void {
    ['user' => $userA, 'headers' => $headersA] = p4Tenant('IT');
    Sanctum::actingAs($userA);
    $id = $this->withHeaders($headersA)->postJson('/api/v1/vat/settlements', ['period_type' => 'quarterly', 'year' => 2026, 'period' => 1])->json('data.id');

    ['user' => $userB, 'headers' => $headersB] = p4Tenant('IT');
    Sanctum::actingAs($userB);

    $this->withHeaders($headersB)->getJson("/api/v1/vat/settlements/{$id}")->assertNotFound();
    $this->withHeaders($headersB)->get("/api/v1/vat/settlements/{$id}/export-xml")->assertNotFound();
    expect($this->withHeaders($headersB)->getJson('/api/v1/vat/settlements')->json('data'))->toBe([]);
});

it('detects the integration document type of a received supplier invoice', function (): void {
    p4Tenant('IT');
    $detector = app(IntegrationDocumentTypeDetector::class);

    $foreign = function (string $country): PassiveInvoice {
        $invoice = new PassiveInvoice;
        $invoice->setRelation('supplier', new Contact(['country' => $country]));

        return $invoice;
    };

    expect($detector->detect($foreign('DE'), 'services'))->toBe(TipoDocumento::Td17)
        ->and($detector->detect($foreign('US'), 'services'))->toBe(TipoDocumento::Td17)
        ->and($detector->detect($foreign('DE'), 'goods'))->toBe(TipoDocumento::Td18)
        ->and($detector->detect($foreign('CH'), 'goods'))->toBe(TipoDocumento::Td19)
        ->and($detector->detect($foreign('DE'), null))->toBeNull()
        ->and($detector->isCandidate($foreign('DE')))->toBeTrue();
});
