<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;
use Modules\Copilot\DTOs\TokenUsage;

/**
 * The model-less default: nothing leaves the installation and no model reasons. It never calls a tool; it answers with the
 * knowledge passages the retriever put in the system prompt, so the regulatory cheat-sheets work without any provider.
 */
final class LocalLlmProvider implements LlmProviderInterface
{
    public function name(): string
    {
        return 'local';
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $system = '';
        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $system .= $message->content."\n";
            }
        }

        $excerpts = [];
        if (preg_match_all('#<knowledge_passage ref="([^"]*)">(.*?)</knowledge_passage>#s', $system, $matches, PREG_SET_ORDER) > 0) {
            foreach (array_slice($matches, 0, 2) as $match) {
                $excerpts[] = '**'.$match[1].'** — '.trim($match[2]);
            }
        }

        $content = $excerpts === []
            ? 'No language model is configured for the Copilot (set COPILOT_DRIVER) and the knowledge base has no passage for this question.'
            : implode("\n\n", $excerpts);

        $tokens = (int) ceil(strlen($system) / 4);

        return new LlmResponse($content, [], new TokenUsage($tokens, (int) ceil(strlen($content) / 4), $this->name(), 'local'));
    }
}
