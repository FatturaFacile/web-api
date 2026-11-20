<?php

declare(strict_types=1);

namespace Modules\Copilot\Enums;

/**
 * Who wrote a message of a conversation.
 */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
}
