<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\Integrations\Models\WebhookDelivery;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/DataResidencyTestHelpers.php';
require_once __DIR__.'/../Integrations/WebhookHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
    Http::preventStrayRequests();
});

it('refuses to deliver a webhook of a Strict-CH tenant to a non-adequate country and records the alert', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    app()->instance(HostResolver::class, new StaticHostResolver(['hooks.vendor.example' => ['93.184.216.34']]));
    $endpoint = webhookEndpointFor($tenant, attributes: ['url' => 'https://hooks.vendor.example/notify']);
    $delivery = webhookPendingDelivery($endpoint);

    $delivery = webhookProcessDelivery($delivery);

    Http::assertNothingSent();
    expect($delivery->status)->not->toBe(WebhookDelivery::StatusSucceeded)
        ->and((string) $delivery->last_error)->toContain('Blocked')
        ->and(CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('purpose', 'webhook')->exists())->toBeTrue();
});
