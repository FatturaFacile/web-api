<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use App\Models\User;
use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Scheduling\ReportDeliveryService;
use Modules\Copilot\Embeddings\EmbeddingProviderManager;
use Modules\Copilot\Llm\LlmProviderManager;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use Modules\Marketplace\Connectors\HttpProfile;
use Modules\Marketplace\Connectors\ProfileConnector;
use Modules\Marketplace\Connectors\WebhookConnector;
use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\HealthCheckResult;
use Tests\Support\FakeAiAdapter;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/DataResidencyTestHelpers.php';
require_once __DIR__.'/../AiCognitive/AiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
    Http::preventStrayRequests();
    $this->tenant = drTenant();
    app(TenantContext::class)->setTenant($this->tenant);
    app()->instance(HostResolver::class, new StaticHostResolver(['hooks.vendor.example' => ['93.184.216.34']]));
});

function egressBlockedPurposes(string $tenantId): array
{
    return CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('purpose')->all();
}

it('blocks scheduled report delivery to a webhook, Telegram and SFTP for a Strict-CH tenant', function (): void {
    drEnforce($this->tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $owner = User::create(['name' => 'o', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $report = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'name' => 'Sales', 'dataset' => 'sales', 'spec' => []]);
    $schedule = ExportSchedule::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $owner->id,
        'designed_report_id' => $report->id,
        'recipient_user_ids' => [],
        'external_emails' => [],
        'delivery_channels' => ['telegram_chat_ids' => ['-100123456'], 'webhook_url' => 'https://hooks.vendor.example/reports'],
        'secret_config_encrypted' => ['webhook_secret' => 'whsec_test', 'sftp' => ['host' => 'sftp.vendor.example', 'username' => 'u']],
    ]);

    $failures = app(ReportDeliveryService::class)->deliver($schedule, $report, new ExportRun(['format' => 'csv', 'locale' => 'en']), '/missing.csv', 'r.csv', 'text/csv', $owner, 'en');

    Http::assertNothingSent();
    expect($failures)->toHaveCount(3)
        ->and(egressBlockedPurposes((string) $this->tenant->id))->toContain('report_delivery_telegram', 'report_delivery_webhook', 'report_delivery_sftp');
});

it('does not block report delivery for a tenant without a residency policy', function (): void {
    Http::fake(['hooks.vendor.example/*' => Http::response('', 204)]);
    $owner = User::create(['name' => 'o', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $report = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'name' => 'Sales', 'dataset' => 'sales', 'spec' => []]);
    $schedule = ExportSchedule::factory()->create([
        'tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'designed_report_id' => $report->id,
        'recipient_user_ids' => [], 'external_emails' => [],
        'delivery_channels' => ['webhook_url' => 'https://hooks.vendor.example/reports'],
        'secret_config_encrypted' => ['webhook_secret' => 'whsec_test'],
    ]);
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(new StaticHostResolver(['hooks.vendor.example' => ['93.184.216.34']])));

    $failures = app(ReportDeliveryService::class)->deliver($schedule, $report, new ExportRun(['format' => 'csv', 'locale' => 'en']), '/missing.csv', 'r.csv', 'text/csv', $owner, 'en');

    expect($failures)->toBe([]);
});

it('blocks the Marketplace webhook connector and the profile connectors for a Strict-CH tenant', function (): void {
    drEnforce($this->tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $context = new ConnectorContext((string) $this->tenant->id, (string) Str::uuid(), WebhookConnector::Key, ['endpoint_url' => 'https://hooks.vendor.example/in'], ['signing_secret' => 'secret-value']);

    $webhook = new WebhookConnector(app(WebhookUrlGuard::class), app(WebhookSigner::class), app(OutboundUrlGuard::class));
    $profile = new ProfileConnector(
        new ConnectorDefinition('vendor-api', 'Vendor', 'Vendor API', ConnectorDefinition::Automation, '1.0.0', [], [], ['orders']),
        new HttpProfile('https://api.vendor.example', ['type' => 'none'], '/health', ['orders' => ['path' => '/orders', 'collection' => null]]),
        app(OutboundUrlGuard::class),
    );

    expect($webhook->checkHealth($context)->status)->toBe(HealthCheckResult::Unhealthy)
        ->and($profile->checkHealth($context)->status)->toBe(HealthCheckResult::Unhealthy)
        ->and(fn () => $profile->pull($context, 'orders', null))->toThrow(ConnectorException::class);

    Http::assertNothingSent();
    expect(egressBlockedPurposes((string) $this->tenant->id))->toContain('marketplace');
});

it('blocks hosted Copilot models and embeddings for a Strict-CH tenant but keeps the local ones', function (): void {
    drEnforce($this->tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    config(['copilot.drivers.openai.api_key' => 'sk', 'copilot.drivers.openai.base_url' => 'https://api.openai.com/v1']);
    $consent = new class implements TenantAiConsentInterface
    {
        public function allowsExternalProviders(string $tenantId): bool
        {
            return true;
        }
    };
    $tenantId = (string) $this->tenant->id;

    expect(fn () => (new LlmProviderManager($consent))->forTenant($tenantId, 'openai'))->toThrow(CrossBorderTransferBlockedException::class)
        ->and(fn () => (new EmbeddingProviderManager($consent))->forTenant($tenantId, 'openai'))->toThrow(CrossBorderTransferBlockedException::class)
        ->and((new LlmProviderManager($consent))->forTenant($tenantId, 'local')->isExternal())->toBeFalse();
});

it('blocks a hosted AI provider in the AI gateway for a Strict-CH tenant and never calls the adapter', function (): void {
    ocrConsent($this->tenant);
    drEnforce($this->tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $openai = new FakeAiAdapter(AiProviderType::OpenAi, fn (): string => '{"ok":true}');
    aiUseAdapters([$openai]);
    aiProvider($this->tenant, AiProviderType::OpenAi, ['model' => 'gpt-4o', 'is_default' => true]);
    $request = new AiCompletionRequest((string) $this->tenant->id, AiTaskType::Classification, 'You classify.', 'Classify this.', DataClassification::Internal);

    expect(fn () => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete($request)))->toThrow(CrossBorderTransferBlockedException::class);
    expect($openai->calls)->toBe([])
        ->and(egressBlockedPurposes((string) $this->tenant->id))->toContain('ai');
});
