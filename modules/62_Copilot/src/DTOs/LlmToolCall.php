<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

final readonly class LlmToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
