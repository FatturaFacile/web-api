<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

final readonly class LlmResponse
{
    /**
     * @param  list<LlmToolCall>  $toolCalls
     */
    public function __construct(
        public string $content,
        public array $toolCalls,
        public TokenUsage $usage,
    ) {}
}
