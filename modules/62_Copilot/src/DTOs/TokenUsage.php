<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

final readonly class TokenUsage
{
    public int $totalTokens;

    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public string $provider = 'local',
        public string $model = '',
    ) {
        $this->totalTokens = $promptTokens + $completionTokens;
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, provider: string, model: string}
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }

    public function plus(self $other): self
    {
        return new self($this->promptTokens + $other->promptTokens, $this->completionTokens + $other->completionTokens, $other->provider, $other->model);
    }
}
