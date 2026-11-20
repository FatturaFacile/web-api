<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

final readonly class LlmRequest
{
    /**
     * @param  list<LlmMessage>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?string $model = null,
        public float $temperature = 0.2,
        public int $maxTokens = 2048,
    ) {}
}
