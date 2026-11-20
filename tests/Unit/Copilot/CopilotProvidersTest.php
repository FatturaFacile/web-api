<?php

declare(strict_types=1);

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Copilot\DTOs\LlmMessage;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmToolCall;
use Modules\Copilot\Embeddings\EmbeddingProviderManager;
use Modules\Copilot\Embeddings\GeminiEmbeddingProvider;
use Modules\Copilot\Embeddings\LocalHashEmbeddingProvider;
use Modules\Copilot\Embeddings\OpenAiCompatibleEmbeddingProvider;
use Modules\Copilot\Llm\AnthropicLlmProvider;
use Modules\Copilot\Llm\CopilotProviderException;
use Modules\Copilot\Llm\GeminiLlmProvider;
use Modules\Copilot\Llm\LlmProviderManager;
use Modules\Copilot\Llm\LocalLlmProvider;
use Modules\Copilot\Llm\OpenAiCompatibleLlmProvider;

function copilotConversationRequest(): LlmRequest
{
    return new LlmRequest(
        messages: [
            new LlmMessage('system', 'Be brief.'),
            new LlmMessage('user', 'Revenue?'),
            new LlmMessage('assistant', '', [new LlmToolCall('call_1', 'semantic_query', ['entity' => 'invoices'])]),
            new LlmMessage('tool', '{"rows":[]}', toolCallId: 'call_1', toolName: 'semantic_query'),
        ],
        tools: [['name' => 'semantic_query', 'description' => 'Query', 'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['entity' => ['type' => 'string']]]]],
    );
}

function copilotConsent(bool $allowed): TenantAiConsentInterface
{
    return new class($allowed) implements TenantAiConsentInterface
    {
        public function __construct(private readonly bool $allowed) {}

        public function allowsExternalProviders(string $tenantId): bool
        {
            return $this->allowed;
        }
    };
}

test('the OpenAI wire format carries tools, tool results and usage', function (): void {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'semantic_query', 'arguments' => '{"entity":"invoices"}']]]]]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7],
    ])]);

    $response = (new OpenAiCompatibleLlmProvider('openai', 'https://api.openai.com/v1', 'sk-test', 'gpt-test', external: true))->complete(copilotConversationRequest());

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('semantic_query')
        ->and($response->toolCalls[0]->arguments)->toBe(['entity' => 'invoices'])
        ->and($response->usage->totalTokens)->toBe(12)
        ->and($response->usage->provider)->toBe('openai');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $body['model'] === 'gpt-test'
            && $body['tools'][0]['function']['name'] === 'semantic_query'
            && $body['messages'][2]['tool_calls'][0]['function']['arguments'] === '{"entity":"invoices"}'
            && $body['messages'][3] === ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"rows":[]}'];
    });
});

test('a self-hosted provider needs no key and stays inside the installation', function (): void {
    Http::fake(['localhost:8000/*' => Http::response(['choices' => [['message' => ['content' => 'Hi']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    $provider = new OpenAiCompatibleLlmProvider('vllm', 'http://localhost:8000/v1', null, 'llama', external: false, requiresKey: false);

    expect($provider->isExternal())->toBeFalse()
        ->and($provider->complete(new LlmRequest([new LlmMessage('user', 'hi')]))->content)->toBe('Hi');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('Anthropic gets the system prompt apart and the tool results of a turn in one user message', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'semantic_query', 'input' => ['entity' => 'quotes']]],
        'usage' => ['input_tokens' => 9, 'output_tokens' => 4],
    ])]);

    $response = (new AnthropicLlmProvider('key', 'https://api.anthropic.com', 'claude-test'))->complete(copilotConversationRequest());

    expect($response->content)->toBe('Let me check.')
        ->and($response->toolCalls[0]->arguments)->toBe(['entity' => 'quotes'])
        ->and($response->usage->totalTokens)->toBe(13);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->hasHeader('x-api-key', 'key')
            && $request->hasHeader('anthropic-version')
            && $body['system'] === 'Be brief.'
            && $body['messages'][1]['content'][0]['type'] === 'tool_use'
            && $body['messages'][2] === ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"rows":[]}']]]
            && $body['tools'][0]['input_schema']['type'] === 'object';
    });
});

test('Gemini gets function declarations without unsupported schema keys and returns function calls', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'semantic_query', 'args' => ['entity' => 'contacts']]]]]]],
        'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
    ])]);

    $response = (new GeminiLlmProvider('gkey', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-test'))->complete(copilotConversationRequest());

    expect($response->toolCalls[0]->name)->toBe('semantic_query')
        ->and($response->toolCalls[0]->arguments)->toBe(['entity' => 'contacts'])
        ->and($response->usage->totalTokens)->toBe(5);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/models/gemini-test:generateContent')
            && $request->hasHeader('x-goog-api-key', 'gkey')
            && $body['systemInstruction']['parts'][0]['text'] === 'Be brief.'
            && ! array_key_exists('additionalProperties', $body['tools'][0]['functionDeclarations'][0]['parameters'])
            && $body['contents'][2]['parts'][0]['functionResponse']['name'] === 'semantic_query';
    });
});

test('a provider without a key, or one that fails, raises a provider exception', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => (new AnthropicLlmProvider(null, 'https://api.anthropic.com', 'm'))->complete(new LlmRequest([new LlmMessage('user', 'x')])))
        ->toThrow(CopilotProviderException::class, 'not configured');
    expect(fn () => (new OpenAiCompatibleLlmProvider('openai', 'https://api.openai.com/v1', 'k', 'm', external: true))->complete(new LlmRequest([new LlmMessage('user', 'x')])))
        ->toThrow(CopilotProviderException::class, 'HTTP 500');
});

test('hosted drivers need the consent of the tenant, local and self-hosted ones do not', function (): void {
    config(['copilot.drivers.openai.api_key' => 'sk', 'copilot.drivers.anthropic.api_key' => 'ak', 'copilot.drivers.gemini.api_key' => 'gk']);
    $denied = new LlmProviderManager(copilotConsent(false));
    $allowed = new LlmProviderManager(copilotConsent(true));

    foreach (['openai', 'anthropic', 'gemini'] as $driver) {
        expect(fn () => $denied->forTenant('t', $driver))->toThrow(ExternalAiNotAllowedException::class)
            ->and($allowed->forTenant('t', $driver)->isExternal())->toBeTrue();
    }

    foreach (['local', 'ollama', 'vllm'] as $driver) {
        expect($denied->forTenant('t', $driver)->isExternal())->toBeFalse();
    }

    expect(fn () => $denied->forTenant('t', 'nope'))->toThrow(InvalidArgumentException::class);
});

test('the embedding drivers map their replies and obey the same consent rule', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
        'generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.4, 0.5]]]),
    ]);

    expect((new OpenAiCompatibleEmbeddingProvider('openai', 'https://api.openai.com/v1', 'k', 'emb', external: true))->embed('x'))->toBe([0.1, 0.2, 0.3])
        ->and((new GeminiEmbeddingProvider('k', 'https://generativelanguage.googleapis.com/v1beta', 'emb'))->embed('x'))->toBe([0.4, 0.5]);

    config(['copilot.drivers.openai.api_key' => 'sk']);

    expect(fn () => (new EmbeddingProviderManager(copilotConsent(false)))->forTenant('t', 'openai'))->toThrow(ExternalAiNotAllowedException::class)
        ->and((new EmbeddingProviderManager(copilotConsent(false)))->forTenant('t', 'ollama')->isExternal())->toBeFalse()
        ->and(fn () => (new EmbeddingProviderManager(copilotConsent(true)))->forTenant('t', 'anthropic'))->toThrow(InvalidArgumentException::class);
});

test('the local embedding is deterministic, normalised and closer for similar texts', function (): void {
    $provider = new LocalHashEmbeddingProvider;
    $a = $provider->embed('Aliquota normale IVA svizzera');
    $b = $provider->embed('aliquota IVA normale in Svizzera');
    $c = $provider->embed('Swissdec lohnmeldung Pensionskasse');
    $cosine = static fn (array $x, array $y): float => array_sum(array_map(static fn (float $p, float $q): float => $p * $q, $x, $y));

    expect($provider->embed('Aliquota normale IVA svizzera'))->toBe($a)
        ->and(round($cosine($a, $a), 6))->toBe(1.0)
        ->and($cosine($a, $b))->toBeGreaterThan($cosine($a, $c));
});

test('the model-less provider answers with the knowledge passages it was given', function (): void {
    $system = 'Rules.<retrieved_knowledge><knowledge_passage ref="[LIVA Art. 25]">The normal rate is 8.1%.</knowledge_passage></retrieved_knowledge>';

    $response = (new LocalLlmProvider)->complete(new LlmRequest([new LlmMessage('system', $system), new LlmMessage('user', 'rate?')]));

    expect($response->content)->toBe('**[LIVA Art. 25]** — The normal rate is 8.1%.')
        ->and($response->toolCalls)->toBe([])
        ->and((new LocalLlmProvider)->isExternal())->toBeFalse();
});
