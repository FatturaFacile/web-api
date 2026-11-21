<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;

require_once __DIR__.'/AiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = ocrCompany('CH');
    $this->tenant = $this->company['tenant'];
});

function aiProviderBody(array $overrides = []): array
{
    return $overrides + ['is_enabled' => true, 'max_data_classification' => 'internal', 'pii_masking_enabled' => true];
}

test('the API key of a provider is stored encrypted, never returned and kept when the form is saved again without it', function (): void {
    ocrActAs($this->tenant, 'owner');

    $response = $this->putJson('/api/v1/ai/settings/providers/openai', aiProviderBody(['api_key' => 'sk-live-very-secret', 'model' => 'gpt-4o', 'monthly_token_budget' => 100000]))
        ->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.has_api_key', true)
        ->assertJsonPath('data.monthly_token_budget', 100000);

    expect($response->getContent())->not->toContain('sk-live-very-secret');

    $raw = (string) DB::table('ai_provider_configs')->where('tenant_id', $this->tenant->id)->value('credentials_encrypted');

    expect($raw)->not->toContain('sk-live-very-secret')->and($raw)->not->toBe('');

    $this->putJson('/api/v1/ai/settings/providers/openai', aiProviderBody(['model' => 'gpt-4o-mini']))->assertOk()->assertJsonPath('data.has_api_key', true);

    $config = aiInTenant($this->tenant, fn () => AiProviderConfig::query()->where('provider', 'openai')->firstOrFail());

    expect($config->apiKey())->toBe('sk-live-very-secret')->and($config->model)->toBe('gpt-4o-mini');

    $this->getJson('/api/v1/ai/settings')->assertOk()->assertJsonPath('data.providers.0.has_api_key', true)->assertJsonMissingPath('data.providers.0.credentials_encrypted');
    expect(DB::table('ai_audit_trails')->where('event_type', 'provider_configured')->count())->toBe(2)
        ->and((string) DB::table('ai_audit_trails')->where('event_type', 'provider_configured')->value('payload_json'))->not->toContain('sk-live');
});

test('a hosted provider can only point at its official address, so a key cannot be sent anywhere else', function (): void {
    ocrActAs($this->tenant, 'owner');

    $this->putJson('/api/v1/ai/settings/providers/openai', aiProviderBody(['endpoint' => 'https://evil.example.com/v1', 'api_key' => 'sk-1']))->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->putJson('/api/v1/ai/settings/providers/anthropic', aiProviderBody(['endpoint' => 'http://api.anthropic.com', 'api_key' => 'k']))->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->putJson('/api/v1/ai/settings/providers/azure_openai', aiProviderBody(['endpoint' => 'https://evil.example.com', 'api_key' => 'k']))->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    $this->putJson('/api/v1/ai/settings/providers/azure_openai', aiProviderBody(['endpoint' => 'https://acme.openai.azure.com', 'api_key' => 'k', 'extra' => ['deployment' => 'gpt4o', 'api_version' => '2024-06-01']]))
        ->assertOk()->assertJsonPath('data.extra.deployment', 'gpt4o');
    $this->putJson('/api/v1/ai/settings/providers/ollama', aiProviderBody(['endpoint' => 'http://ollama.internal:11434', 'max_data_classification' => 'confidential_pii', 'pii_masking_enabled' => false]))->assertOk();
    $this->putJson('/api/v1/ai/settings/providers/skynet', aiProviderBody())->assertUnprocessable();
});

test('only one provider is the default of a tenant', function (): void {
    ocrActAs($this->tenant, 'owner');

    $this->putJson('/api/v1/ai/settings/providers/ollama', aiProviderBody(['is_default' => true, 'max_data_classification' => 'confidential_pii']))->assertOk();
    $this->putJson('/api/v1/ai/settings/providers/gemini', aiProviderBody(['is_default' => true, 'api_key' => 'g-key']))->assertOk();

    expect(DB::table('ai_provider_configs')->where('tenant_id', $this->tenant->id)->where('is_default', true)->pluck('provider')->all())->toBe(['gemini']);
});

test('the automation rules are configured per workflow with a threshold and an amount limit', function (): void {
    ocrActAs($this->tenant, 'admin');

    $this->putJson('/api/v1/ai/settings/rules/extraction_sales_quote', ['is_enabled' => true, 'auto_approve_enabled' => true, 'auto_approve_min_confidence' => 0.97, 'max_auto_amount' => 2500, 'data_classification' => 'confidential_pii'])
        ->assertOk()
        ->assertJsonPath('data.auto_approve_min_confidence', 0.97)
        ->assertJsonPath('data.max_auto_amount', 2500);

    $this->putJson('/api/v1/ai/settings/rules/extraction_sales_quote', ['is_enabled' => true, 'auto_approve_enabled' => true, 'auto_approve_min_confidence' => 0.2, 'data_classification' => 'internal'])->assertUnprocessable()->assertJsonValidationErrors('auto_approve_min_confidence');
    $this->putJson('/api/v1/ai/settings/rules/not_a_workflow', ['is_enabled' => true, 'auto_approve_enabled' => false, 'auto_approve_min_confidence' => 0.9, 'data_classification' => 'internal'])->assertNotFound();

    $settings = $this->getJson('/api/v1/ai/settings')->assertOk()->assertJsonPath('data.external_providers_allowed', false);
    $rule = collect($settings->json('data.rules'))->firstWhere('workflow', 'extraction_sales_quote');
    $default = collect($settings->json('data.rules'))->firstWhere('workflow', 'extraction_contact');

    expect($rule['configured'])->toBeTrue()->and($rule['auto_approve_enabled'])->toBeTrue()
        ->and($default['configured'])->toBeFalse()->and($default['auto_approve_enabled'])->toBeFalse()
        ->and(collect($settings->json('data.supported_providers'))->pluck('provider')->all())->toBe(['ollama', 'openai', 'anthropic', 'gemini', 'azure_openai']);
});

test('the settings are for owners and admins, the queue for the finance and sales teams, the copilot for every member', function (): void {
    $sales = ocrActAs($this->tenant, 'sales');
    $this->getJson('/api/v1/ai/settings')->assertForbidden();
    $this->putJson('/api/v1/ai/settings/providers/ollama', aiProviderBody())->assertForbidden();
    $this->getJson('/api/v1/ai/usage-audit')->assertForbidden();
    $this->getJson('/api/v1/ai/suggestions')->assertOk();
    $this->postJson('/api/v1/ai/suggestions/extract', ['kind' => 'contact', 'source_type' => 'email', 'text' => 'short'])->assertStatus(422);

    expect($sales->id)->not->toBeNull();

    $member = ocrActAs($this->tenant, 'technician');
    $this->getJson('/api/v1/ai/suggestions')->assertForbidden();
    $this->getJson('/api/v1/ai/insights')->assertForbidden();
    $this->postJson('/api/v1/ai/copilot/query', ['question' => 'How many invoices are open?'])->assertStatus(503);

    expect($member->id)->not->toBeNull();
});

test('usage, cost and the verified audit chain are reported to the admins', function (): void {
    ocrActAs($this->tenant, 'owner');
    DB::table('ai_usage_logs')->insert([
        ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider' => 'openai', 'model' => 'gpt-4o', 'task_type' => 'extraction', 'data_classification' => 'internal', 'prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500, 'cost_usd' => 0.0123, 'latency_ms' => 300, 'cache_hit' => false, 'pii_masked' => true, 'status' => 'ok', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider' => 'openai', 'model' => 'gpt-4o', 'task_type' => 'extraction', 'data_classification' => 'internal', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_usd' => 0, 'latency_ms' => 0, 'cache_hit' => false, 'pii_masked' => false, 'status' => 'error', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->putJson('/api/v1/ai/settings/providers/ollama', aiProviderBody(['max_data_classification' => 'confidential_pii']))->assertOk();

    $response = $this->getJson('/api/v1/ai/usage-audit?verify_chain=1')->assertOk()
        ->assertJsonPath('data.usage.total_tokens', 1500)
        ->assertJsonPath('data.usage.by_provider.0.provider', 'openai')
        ->assertJsonPath('data.usage.by_provider.0.calls', 2)
        ->assertJsonPath('data.usage.by_provider.0.failures', 1)
        ->assertJsonPath('data.chain.valid', true)
        ->assertJsonPath('data.audit.0.event_type', 'provider_configured');

    expect($response->json('data.usage.total_cost_usd'))->toBe(0.0123)
        ->and($response->json('meta.total'))->toBeGreaterThanOrEqual(1);
});
