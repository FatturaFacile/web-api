<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Events\UnauthorizedCrossBorderTransferBlockedEvent;
use Modules\DataResidency\Domain\Exceptions\AiResidencyBoundaryException;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\DataResidency\Infrastructure\Models\CrossBorderTransferRule;
use Modules\DataResidency\Infrastructure\Services\ResidencyRuntime;
use Modules\DataResidency\Infrastructure\Services\SovereignAiAndVectorRouter;
use Modules\DataResidency\Infrastructure\Services\SovereignS3StorageRouter;

require_once __DIR__.'/DataResidencyTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
    Http::fake(['*' => Http::response('ok')]);
});

/** Runs the callback as the tenant (the request context the egress macro reads). */
function drAsTenant(Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
}

it('blocks a webhook to a non-adequate country with 451 and records an immutable alert', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drAsTenant($tenant);
    Event::fake([UnauthorizedCrossBorderTransferBlockedEvent::class]);

    try {
        Http::residencyGuarded('webhook', 'US')->post('https://hooks.vendor.example/notify?token=secret', ['invoice' => 1]);
        $this->fail('The transfer to the US must be blocked.');
    } catch (CrossBorderTransferBlockedException $exception) {
        expect($exception->httpStatus())->toBe(451)->and($exception->errorCode())->toBe('CROSS_BORDER_TRANSFER_BLOCKED');
    }

    Http::assertNothingSent();
    Event::assertDispatched(UnauthorizedCrossBorderTransferBlockedEvent::class, static fn (UnauthorizedCrossBorderTransferBlockedEvent $event): bool => $event->destinationCountryIso2 === 'US' && $event->purpose === 'webhook');

    $alert = CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($alert->destination_country_iso2)->toBe('US')->and($alert->purpose)->toBe('webhook')->and($alert->target)->not->toContain('secret')
        ->and(fn () => $alert->update(['reason' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $alert->delete())->toThrow(LogicException::class);
});

it('blocks a destination whose country cannot be verified', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drAsTenant($tenant);

    expect(fn () => Http::residencyGuarded('export')->post('https://api.cloud-provider.com/upload'))->toThrow(CrossBorderTransferBlockedException::class, 'could not be verified');
    expect(CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('destination_country_iso2'))->toBeNull();
});

it('lets an adequate or in-zone destination through', function (string $url, JurisdictionZone $zone, string $site): void {
    $tenant = drTenant();
    drEnforce($tenant, $site, $zone);
    drAsTenant($tenant);

    expect(Http::residencyGuarded('webhook')->post($url)->body())->toBe('ok')
        ->and(CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'Swiss host under Strict-CH-Only' => ['https://erp.partner.ch/hook', JurisdictionZone::StrictChOnly, 'ch-zrh'],
    'German host (EEA, Swiss adequacy) under Strict-CH-Only' => ['https://erp.partner.de/hook', JurisdictionZone::StrictChOnly, 'ch-zrh'],
    'British host (adequate) under Strict-CH-Only' => ['https://erp.partner.co.uk/hook', JurisdictionZone::StrictChOnly, 'ch-zrh'],
    'Swiss host (EU adequacy) under Strict-IT-Only' => ['https://erp.partner.ch/hook', JurisdictionZone::StrictItOnly, 'it-mil'],
    'Italian host under Strict-IT-Only' => ['https://erp.partner.it/hook', JurisdictionZone::StrictItOnly, 'it-mil'],
]);

it('blocks the United States, China and Russia under every zone', function (string $country): void {
    foreach ([[JurisdictionZone::StrictChOnly, 'ch-zrh'], [JurisdictionZone::StrictItOnly, 'it-mil']] as [$zone, $site]) {
        app(TenantContext::class)->clear();
        $tenant = drTenant();
        drEnforce($tenant, $site, $zone);
        drAsTenant($tenant);

        expect(fn () => Http::residencyGuarded('ai-call', $country)->post('https://llm.example/v1'))->toThrow(CrossBorderTransferBlockedException::class);
    }
})->with(['US', 'CN', 'RU']);

it('honours a tenant override of the adequacy matrix and the SCC/TIA state', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drAsTenant($tenant);

    expect(fn () => Http::residencyGuarded('export', 'US')->post('https://x.example'))->toThrow(CrossBorderTransferBlockedException::class);

    CrossBorderTransferRule::query()->withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id, 'source_jurisdiction' => 'Strict-CH-Only', 'destination_country_iso2' => 'US', 'destination_country_name' => 'United States',
        'has_ch_adequacy' => false, 'has_eu_adequacy' => false, 'decision_status' => 'REQUIRES_SCC_TIA', 'legal_basis_reference' => 'Art. 16 Abs. 2 nLPD (SCC + TIA)',
    ]);
    // SCC/TIA still blocks while the strict egress block is on.
    expect(fn () => Http::residencyGuarded('export', 'US')->post('https://x.example'))->toThrow(CrossBorderTransferBlockedException::class);

    $relaxed = drTenant();
    drEnforce($relaxed, 'ch-zrh', JurisdictionZone::StrictChOnly, null, false);
    CrossBorderTransferRule::query()->withoutGlobalScopes()->create([
        'tenant_id' => $relaxed->id, 'source_jurisdiction' => 'Strict-CH-Only', 'destination_country_iso2' => 'US', 'destination_country_name' => 'United States',
        'has_ch_adequacy' => false, 'has_eu_adequacy' => false, 'decision_status' => 'REQUIRES_SCC_TIA', 'legal_basis_reference' => 'Art. 16 Abs. 2 nLPD (SCC + TIA)',
    ]);
    drAsTenant($relaxed);
    expect(Http::residencyGuarded('export', 'US')->post('https://x.example')->body())->toBe('ok');
});

it('does not interfere with a tenant that has no residency policy', function (): void {
    $tenant = drTenant();
    drAsTenant($tenant);

    expect(Http::residencyGuarded('webhook', 'US')->post('https://hooks.example')->body())->toBe('ok');
});

it('tests an outbound endpoint through the API without side effects', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);

    $this->postJson('/api/v1/sovereignty/cross-border-rules/validate', ['purpose' => 'webhook', 'target' => 'https://hooks.partner.us/x', 'destination_country_iso2' => 'US'])
        ->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.decision_status', 'BLOCKED')->assertJsonPath('data.legal_basis_reference', 'Art. 16 Abs. 1 nLPD / Annex 1 DSV');

    $this->postJson('/api/v1/sovereignty/cross-border-rules/validate', ['purpose' => 'webhook', 'target' => 'https://hooks.partner.de/x'])
        ->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.destination_country_iso2', 'DE');

    expect(CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->count())->toBe(0);
    $this->postJson('/api/v1/sovereignty/cross-border-rules/validate', ['purpose' => 'x'])->assertStatus(422);
});

it('serves the adequacy matrix of the statutory baseline', function (): void {
    drActAs(drTenant());

    $rules = collect($this->getJson('/api/v1/sovereignty/cross-border-rules')->assertOk()->json('data'));
    $swiss = $rules->where('source_jurisdiction', 'Strict-CH-Only')->keyBy('destination_country_iso2');

    expect($swiss['DE']['decision_status'])->toBe('ALLOWED')->and($swiss['GB']['has_ch_adequacy'])->toBeTrue()
        ->and($swiss['US']['decision_status'])->toBe('BLOCKED')->and($swiss['CN']['has_ch_adequacy'])->toBeFalse()
        ->and($swiss['US']['legal_basis_reference'])->toContain('nLPD')
        ->and($rules->where('source_jurisdiction', 'Strict-IT-Only')->firstWhere('destination_country_iso2', 'CH')['legal_basis_reference'])->toBe('GDPR Art. 45');
});

it('keeps the AI inference on the bound sovereign node', function (): void {
    $tenant = drTenant();
    $policy = drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $router = app(SovereignAiAndVectorRouter::class);

    expect($router->inferenceEndpoint($policy))->toBe('https://ch-zrh-ai-01.fatturafacile.eu')
        ->and($router->vectorConnectionName($policy))->toBe('pgsql_ch_zrh');
    $router->assertEndpointIsSovereign($policy, 'https://ch-zrh-ai-01.fatturafacile.eu/v1/chat');

    expect(fn () => $router->assertEndpointIsSovereign($policy, 'https://api.openai.com/v1/chat'))->toThrow(AiResidencyBoundaryException::class)
        ->and(fn () => $router->assertEndpointIsSovereign($policy, 'https://it-mil-ai-01.fatturafacile.eu/v1/chat'))->toThrow(AiResidencyBoundaryException::class);
});

it('pins the object storage to the regional endpoint and refuses cross-region replication', function (): void {
    $tenant = drTenant();
    $policy = drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $router = app(SovereignS3StorageRouter::class);

    $config = $router->diskConfig($policy);
    expect($config)->toMatchArray(['driver' => 's3', 'region' => 'eu-central-2', 'bucket' => 'ff-ch-zrh-s3-01', 'visibility' => 'private'])
        ->and($config['endpoint'])->toBe('https://ch-zrh-s3-01.fatturafacile.eu')
        ->and($config['root'])->toBe('tenants/'.$tenant->id.'/default');

    expect(fn () => $router->assertNoCrossRegionReplication(['replication' => ['eu-south-1']]))->toThrow(CrossBorderResidencyViolationException::class);

    $milan = drEnforce(drTenant(), 'it-mil', JurisdictionZone::StrictItOnly);
    expect($router->diskConfig($milan)['region'])->toBe('eu-south-1');
});

it('routes the queue of the tenant to its sovereign broker', function (): void {
    $tenant = drTenant();
    $policy = drEnforce($tenant, 'it-tor', JurisdictionZone::StrictItOnly);
    $runtime = app(ResidencyRuntime::class);
    $runtime->bind($tenant->id, null, $policy);

    expect($runtime->queueRoutingKey())->toBe('it-tor-q-01');
});
