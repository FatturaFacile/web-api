<?php

declare(strict_types=1);

namespace Modules\Copilot\Contracts;

/**
 * Turns a text into a vector for the similarity search of the knowledge base.
 */
interface EmbeddingProviderInterface
{
    public function name(): string;

    public function isExternal(): bool;

    /**
     * @return list<float>
     */
    public function embed(string $text): array;
}
