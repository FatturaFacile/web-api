<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\ValueObjects;

use JsonException;

final readonly class AiCompletionResult
{
    public int $totalTokens;

    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $latencyMs = 0,
        public bool $cacheHit = false,
        public bool $piiMasked = false,
        public float $costUsd = 0.0,
    ) {
        $this->totalTokens = $promptTokens + $completionTokens;
    }

    /**
     * The answer decoded as a JSON object, tolerating a Markdown code fence around it; null when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $text = trim($this->content);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $fenced) === 1) {
            $text = trim($fenced[1]);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function withContent(string $content, bool $cacheHit = false, bool $piiMasked = false): self
    {
        return new self($content, $this->provider, $this->model, $this->promptTokens, $this->completionTokens, $this->latencyMs, $cacheHit, $piiMasked, $this->costUsd);
    }

    public function withCost(float $costUsd): self
    {
        return new self($this->content, $this->provider, $this->model, $this->promptTokens, $this->completionTokens, $this->latencyMs, $this->cacheHit, $this->piiMasked, $costUsd);
    }
}
