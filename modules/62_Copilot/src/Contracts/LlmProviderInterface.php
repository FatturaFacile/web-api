<?php

declare(strict_types=1);

namespace Modules\Copilot\Contracts;

use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;

/**
 * A chat model with tool calling. An implementation maps the request to the wire format of one provider.
 */
interface LlmProviderInterface
{
    public function name(): string;

    /**
     * Whether the data leaves the installation (hosted provider) or stays inside it (local, Ollama, vLLM).
     */
    public function isExternal(): bool;

    public function complete(LlmRequest $request): LlmResponse;
}
