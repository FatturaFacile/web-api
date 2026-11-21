<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Infrastructure\Models\AiAutomationRule;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\TenantProviderResolver;
use Tests\Support\FakeAiGateway;

require_once __DIR__.'/../OcrExtraction/OcrTestHelpers.php';

/**
 * Runs the callback inside the tenant context.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function aiInTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

/**
 * Replaces the adapters of the layer by fakes, so a real gateway can be tested without a network.
 *
 * @param  list<AiAdapterInterface>  $adapters
 */
function aiUseAdapters(array $adapters): void
{
    app()->bind(TenantProviderResolver::class, fn ($app): TenantProviderResolver => new TenantProviderResolver($adapters, $app->make(TenantAiConsentInterface::class)));
}

/**
 * Replaces the gateway by a fake that answers with the fixture of the callback.
 *
 * @param  Closure(AiCompletionRequest): (array<string, mixed>|string)  $answer
 */
function aiFakeGateway(Closure $answer): FakeAiGateway
{
    $gateway = new FakeAiGateway($answer);
    app()->instance(AiProviderGatewayInterface::class, $gateway);

    return $gateway;
}

/**
 * Configures a provider of the tenant.
 *
 * @param  array<string, mixed>  $attributes
 */
function aiProvider(Tenant $tenant, AiProviderType $provider, array $attributes = []): AiProviderConfig
{
    return aiInTenant($tenant, static fn (): AiProviderConfig => AiProviderConfig::create($attributes + [
        'tenant_id' => $tenant->id,
        'provider' => $provider,
        'is_enabled' => true,
        'is_default' => false,
        'max_data_classification' => $provider->isLocal() ? DataClassification::ConfidentialPii : DataClassification::Internal,
        'pii_masking_enabled' => true,
        'credentials_encrypted' => $provider->requiresKey() ? ['api_key' => 'sk-test-secret'] : null,
    ]));
}

/**
 * Sets the zero-click policy of a workflow.
 */
function aiRule(Tenant $tenant, string $workflow, bool $auto, float $minConfidence = 0.9, ?float $maxAmount = 1000.0): AiAutomationRule
{
    return aiInTenant($tenant, static fn (): AiAutomationRule => AiAutomationRule::create([
        'tenant_id' => $tenant->id,
        'workflow' => $workflow,
        'is_enabled' => true,
        'auto_approve_enabled' => $auto,
        'auto_approve_min_confidence' => $minConfidence,
        'max_auto_amount' => $maxAmount,
        'data_classification' => DataClassification::ConfidentialPii,
    ]));
}

/**
 * A pending suggestion of the tenant.
 *
 * @param  array<string, mixed>  $payload
 */
function aiSuggestion(Tenant $tenant, SuggestionKind $kind, array $payload, float $confidence = 0.8): AiSuggestion
{
    return aiInTenant($tenant, static fn (): AiSuggestion => AiSuggestion::create([
        'tenant_id' => $tenant->id,
        'kind' => $kind,
        'workflow' => $kind->workflow(),
        'source_type' => 'email',
        'source_text' => 'Please quote 2 chairs.',
        'proposed_payload_json' => $payload,
        'field_confidences_json' => ['customer' => 0.8],
        'confidence' => $confidence,
        'status' => SuggestionStatus::Pending,
        'priority' => 'normal',
        'fingerprint' => hash('sha256', (string) microtime(true).random_int(1, 999999)),
    ]));
}

/**
 * The answer of a model that read a request for quotation.
 *
 * @return array<string, mixed>
 */
function aiQuoteAnswer(string $company = 'Muster AG', float $overall = 0.97, float $unitPrice = 100.0): array
{
    return [
        'customer' => ['company_name' => $company, 'email' => null, 'vat_number' => null],
        'currency' => 'CHF',
        'valid_until' => null,
        'notes' => 'Request by email',
        'items' => [['description' => 'Office chair', 'quantity' => 2, 'unit_price' => $unitPrice, 'tax_rate' => 8.1, 'uom' => 'PCS']],
        'field_confidences' => ['customer' => 0.97, 'items' => 0.96, 'currency' => 0.99],
        'overall_confidence' => $overall,
        'reasoning' => 'The customer names two chairs.',
    ];
}
