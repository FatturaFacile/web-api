<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

/**
 * What a read tool returns: rows for the model, plus what the client renders (chart hint, entity citations).
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $visualization
     * @param  list<array<string, mixed>>  $citations
     */
    public function __construct(
        public array $data,
        public ?array $visualization = null,
        public array $citations = [],
    ) {}
}
