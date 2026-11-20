<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

/**
 * A message sent to the model. Roles: system, user, assistant (possibly with tool calls) and tool (the result of a call).
 */
final readonly class LlmMessage
{
    /**
     * @param  list<LlmToolCall>  $toolCalls
     */
    public function __construct(
        public string $role,
        public string $content,
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {}
}
