<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;
use Modules\Copilot\DTOs\LlmToolCall;
use Modules\Copilot\DTOs\TokenUsage;
use RuntimeException;

/**
 * A model that answers from a script, for the Copilot tests: each call takes the next step (a response, or a closure that
 * receives the request and returns one) and every request is recorded.
 */
final class ScriptedLlmProvider implements LlmProviderInterface
{
    /**
     * @var list<LlmRequest>
     */
    public array $requests = [];

    /**
     * @param  list<LlmResponse|Closure(LlmRequest): LlmResponse>  $script
     */
    public function __construct(private array $script, private readonly bool $external = false) {}

    public static function says(string $text, int $tokens = 10): LlmResponse
    {
        return new LlmResponse($text, [], new TokenUsage($tokens, $tokens, 'scripted', 'scripted-1'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function calls(string $tool, array $arguments, int $tokens = 10): LlmResponse
    {
        return new LlmResponse('', [new LlmToolCall('call_'.$tool, $tool, $arguments)], new TokenUsage($tokens, $tokens, 'scripted', 'scripted-1'));
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $step = array_shift($this->script) ?? throw new RuntimeException('The scripted model was called more often than scripted.');

        return $step instanceof Closure ? $step($request) : $step;
    }
}
