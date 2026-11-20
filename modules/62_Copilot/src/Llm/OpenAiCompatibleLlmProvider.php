<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use Illuminate\Support\Facades\Http;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmMessage;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;
use Modules\Copilot\DTOs\LlmToolCall;
use Modules\Copilot\DTOs\TokenUsage;

/**
 * Chat completions with tool calling over the OpenAI wire format. The same adapter serves OpenAI, a self-hosted vLLM
 * server and Ollama (through its /v1 endpoint), which is why the name and the residency flag are constructor arguments.
 */
final readonly class OpenAiCompatibleLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private string $name,
        private string $baseUrl,
        private ?string $apiKey,
        private ?string $defaultModel,
        private bool $external,
        private bool $requiresKey = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?? $this->defaultModel;

        if (($this->requiresKey && empty($this->apiKey)) || empty($model)) {
            throw CopilotProviderException::notConfigured($this->name);
        }

        $body = [
            'model' => $model,
            'messages' => array_map($this->message(...), $request->messages),
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'type' => 'function',
                'function' => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['parameters']],
            ], $request->tools);
        }

        $pending = Http::timeout(60)->acceptJson();

        if (! empty($this->apiKey)) {
            $pending = $pending->withToken($this->apiKey);
        }

        $response = $pending->post(rtrim($this->baseUrl, '/').'/chat/completions', $body);

        if (! $response->successful()) {
            throw CopilotProviderException::failed($this->name, $response->status());
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw CopilotProviderException::unreadable($this->name);
        }

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $toolCalls[] = new LlmToolCall((string) ($call['id'] ?? uniqid('call_', true)), (string) ($call['function']['name'] ?? ''), is_array($arguments) ? $arguments : []);
        }

        return new LlmResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            usage: new TokenUsage((int) $response->json('usage.prompt_tokens', 0), (int) $response->json('usage.completion_tokens', 0), $this->name, (string) $model),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function message(LlmMessage $message): array
    {
        if ($message->role === 'tool') {
            return ['role' => 'tool', 'tool_call_id' => $message->toolCallId, 'content' => $message->content];
        }

        $wire = ['role' => $message->role, 'content' => $message->content];

        if ($message->toolCalls !== []) {
            $wire['tool_calls'] = array_map(static fn (LlmToolCall $call): array => [
                'id' => $call->id,
                'type' => 'function',
                'function' => ['name' => $call->name, 'arguments' => json_encode((object) $call->arguments, JSON_THROW_ON_ERROR)],
            ], $message->toolCalls);
        }

        return $wire;
    }
}
