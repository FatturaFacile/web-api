<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiPolicyViolationException;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\Exceptions\AiQuotaExceededException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Infrastructure\Models\AiAuditTrail;
use Modules\AiCognitive\Infrastructure\Models\AiUsageLog;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Tests\Support\FakeAiAdapter;

require_once __DIR__.'/AiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['ai_cognitive.resilience.max_attempts' => 1, 'ai_cognitive.resilience.backoff_ms' => 0, 'ai_cognitive.resilience.circuit_failure_threshold' => 2]);
    $this->company = ocrCompany('CH');
    $this->tenant = $this->company['tenant'];
});

function aiRequest(string $tenantId, string $prompt = 'Classify this.', DataClassification $classification = DataClassification::Internal, AiTaskType $task = AiTaskType::Classification, bool $cacheable = false): AiCompletionRequest
{
    return new AiCompletionRequest($tenantId, $task, 'You classify.', $prompt, $classification, null, cacheable: $cacheable);
}

test('a tenant without configuration runs on the local provider and the call is logged and audited', function (): void {
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{"ok":true}');
    aiUseAdapters([$local]);

    $result = aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id)));

    expect($result->provider)->toBe('ollama')
        ->and($result->json())->toBe(['ok' => true])
        ->and($result->totalTokens)->toBe(150);

    $log = aiInTenant($this->tenant, fn () => AiUsageLog::query()->firstOrFail());

    expect($log->status)->toBe('ok')
        ->and($log->provider)->toBe('ollama')
        ->and($log->total_tokens)->toBe(150)
        ->and((float) $log->cost_usd)->toBe(0.0)
        ->and(aiInTenant($this->tenant, fn () => AiAuditTrail::query()->where('event_type', 'inference')->count()))->toBe(1);
});

test('a hosted provider is only offered to a tenant that allowed external AI providers', function (): void {
    $openai = new FakeAiAdapter(AiProviderType::OpenAi, fn (): string => '{"ok":true}');
    aiUseAdapters([$openai]);
    aiProvider($this->tenant, AiProviderType::OpenAi, ['model' => 'gpt-4o']);

    expect(fn () => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id))))
        ->toThrow(AiPolicyViolationException::class);
    expect($openai->calls)->toBe([]);

    ocrConsent($this->tenant);

    $result = aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id)));

    expect($result->provider)->toBe('openai')
        ->and((float) $result->costUsd)->toBeGreaterThan(0.0);
});

test('a hosted provider never sees IBANs, emails or names, and the answer is re-hydrated locally', function (): void {
    ocrConsent($this->tenant);
    $openai = new FakeAiAdapter(AiProviderType::OpenAi, function (string $system, string $prompt): string {
        preg_match('/\[\[EMAIL_\d+\]\]/', $prompt, $token);

        return 'Reply to '.($token[0] ?? 'nobody');
    });
    aiUseAdapters([$openai]);
    aiProvider($this->tenant, AiProviderType::OpenAi, ['model' => 'gpt-4o']);

    $result = aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest(
        (string) $this->tenant->id,
        'Dear Anna Keller, please pay CH93 0076 2011 6238 5295 7. Contact anna.keller@example.ch',
    )));

    expect($openai->calls[0]['prompt'])->not->toContain('CH93 0076')
        ->and($openai->calls[0]['prompt'])->not->toContain('anna.keller@example.ch')
        ->and($openai->calls[0]['prompt'])->not->toContain('Anna Keller')
        ->and($result->content)->toBe('Reply to anna.keller@example.ch')
        ->and($result->piiMasked)->toBeTrue();
});

test('confidential data goes to the local provider even when a hosted one is the default', function (): void {
    ocrConsent($this->tenant);
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    $openai = new FakeAiAdapter(AiProviderType::OpenAi, fn (): string => '{}');
    aiUseAdapters([$local, $openai]);
    aiProvider($this->tenant, AiProviderType::OpenAi, ['is_default' => true, 'pii_masking_enabled' => false]);
    aiProvider($this->tenant, AiProviderType::Ollama);

    aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, 'Salary of Anna', DataClassification::ConfidentialPii, AiTaskType::AnomalyReasoning)));

    expect($local->calls)->toHaveCount(1)->and($openai->calls)->toBe([]);
});

test('reasoning tasks go to the hosted frontier model and light tasks stay local', function (): void {
    ocrConsent($this->tenant);
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    $anthropic = new FakeAiAdapter(AiProviderType::Anthropic, fn (): string => '{}');
    aiUseAdapters([$local, $anthropic]);
    aiProvider($this->tenant, AiProviderType::Anthropic, ['model' => 'claude-sonnet-5-5', 'light_model' => 'claude-haiku-5-5']);
    aiProvider($this->tenant, AiProviderType::Ollama);

    aiInTenant($this->tenant, function (): void {
        app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, 'Tag this', DataClassification::Internal, AiTaskType::Tagging));
        app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, 'Reason about this', DataClassification::Internal, AiTaskType::ContractAnalysis));
    });

    expect($local->calls)->toHaveCount(1)->and($anthropic->calls)->toHaveCount(1)
        ->and($anthropic->calls[0]['model'])->toBe('claude-sonnet-5-5');
});

test('a failing provider hands over to the next one and the circuit breaker stops calling it', function (): void {
    ocrConsent($this->tenant);
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    $local->fail = true;
    $openai = new FakeAiAdapter(AiProviderType::OpenAi, fn (): string => '{"from":"openai"}');
    aiUseAdapters([$local, $openai]);
    aiProvider($this->tenant, AiProviderType::Ollama, ['is_default' => true]);
    aiProvider($this->tenant, AiProviderType::OpenAi, ['model' => 'gpt-4o']);

    $answers = aiInTenant($this->tenant, fn (): array => array_map(
        fn (int $n) => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, "Question {$n}"))->provider,
        [1, 2, 3, 4],
    ));

    expect($answers)->toBe(['openai', 'openai', 'openai', 'openai'])
        ->and($local->calls)->toHaveCount(2);

    $statuses = aiInTenant($this->tenant, fn () => AiUsageLog::query()->where('provider', 'ollama')->pluck('status')->all());

    expect($statuses)->toContain('error', 'circuit_open');
});

test('when every provider fails the error is reported', function (): void {
    $local = new FakeAiAdapter(AiProviderType::Ollama);
    $local->fail = true;
    aiUseAdapters([$local]);

    expect(fn () => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id))))
        ->toThrow(AiProviderUnavailableException::class);
});

test('the monthly token budget of a provider stops further calls', function (): void {
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    aiUseAdapters([$local]);
    aiProvider($this->tenant, AiProviderType::Ollama, ['monthly_token_budget' => 100]);

    aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, 'First')));

    expect(fn () => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, 'Second'))))
        ->toThrow(AiQuotaExceededException::class)
        ->and($local->calls)->toHaveCount(1);
});

test('the rate limit of a provider is enforced per minute', function (): void {
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    aiUseAdapters([$local]);
    aiProvider($this->tenant, AiProviderType::Ollama, ['rate_limit_per_minute' => 2]);

    $call = fn (string $prompt) => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, $prompt)));
    $call('One');
    $call('Two');

    expect(fn () => $call('Three'))->toThrow(AiQuotaExceededException::class);
});

test('a repeated question is answered from the cache without a model call', function (): void {
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{"n":1}');
    aiUseAdapters([$local]);

    $ask = fn (string $prompt) => aiInTenant($this->tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $this->tenant->id, $prompt, cacheable: true)));

    $first = $ask('What is the VAT rate?');
    $second = $ask("what is   the VAT rate?\n");

    expect($local->calls)->toHaveCount(1)
        ->and($first->cacheHit)->toBeFalse()
        ->and($second->cacheHit)->toBeTrue()
        ->and($second->content)->toBe($first->content)
        ->and(aiInTenant($this->tenant, fn () => AiUsageLog::query()->where('cache_hit', true)->count()))->toBe(1);
});

test('the cache is not shared between tenants', function (): void {
    $other = ocrCompany('IT');
    $local = new FakeAiAdapter(AiProviderType::Ollama, fn (): string => '{}');
    aiUseAdapters([$local]);

    foreach ([$this->tenant, $other['tenant']] as $tenant) {
        aiInTenant($tenant, fn () => app(AiProviderGatewayInterface::class)->complete(aiRequest((string) $tenant->id, 'Same question', cacheable: true)));
    }

    expect($local->calls)->toHaveCount(2);
});

test('the audit trail is a hash chain that detects a changed row and refuses edits', function (): void {
    $auditor = app(AiAuditor::class);
    $tenantId = (string) $this->tenant->id;

    aiInTenant($this->tenant, function () use ($auditor, $tenantId): void {
        $auditor->record($tenantId, 'suggestion_created', null, 'ai_suggestion', 'a', 'ollama', 'llama3.3', 0.91, ['kind' => 'sales_quote']);
        $auditor->record($tenantId, 'suggestion_approved', null, 'ai_suggestion', 'a', null, null, 0.91, []);
        $auditor->record($tenantId, 'suggestion_rejected', null, 'ai_suggestion', 'b', null, null, null, []);
    });

    expect(aiInTenant($this->tenant, fn () => $auditor->verify($tenantId)))->toBe(['valid' => true, 'checked' => 3, 'broken_at' => null]);

    $row = aiInTenant($this->tenant, fn () => AiAuditTrail::query()->where('sequence', 2)->firstOrFail());

    expect(fn () => aiInTenant($this->tenant, fn () => $row->update(['event_type' => 'tampered'])))->toThrow(LogicException::class)
        ->and(fn () => aiInTenant($this->tenant, fn () => $row->delete()))->toThrow(LogicException::class);

    DB::table('ai_audit_trails')->where('id', $row->id)->update(['payload_json' => '{"edited":true}']);

    expect(aiInTenant($this->tenant, fn () => $auditor->verify($tenantId)))->toBe(['valid' => false, 'checked' => 1, 'broken_at' => 2]);
});

test('the audit chains of two tenants are independent', function (): void {
    $other = ocrCompany('IT');
    $auditor = app(AiAuditor::class);

    aiInTenant($this->tenant, fn () => $auditor->record((string) $this->tenant->id, 'inference'));
    aiInTenant($other['tenant'], fn () => $auditor->record((string) $other['tenant']->id, 'inference'));

    expect(DB::table('ai_audit_trails')->where('tenant_id', $other['tenant']->id)->value('sequence'))->toBe(1)
        ->and(aiInTenant($other['tenant'], fn () => $auditor->verify((string) $other['tenant']->id))['valid'])->toBeTrue();
});
