<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

/**
 * The dry run of a mutating tool: what would happen, shown to the human before they confirm.
 */
final readonly class ProposedActionPreview
{
    /**
     * @param  array<string, mixed>  $arguments  validated and normalised arguments
     * @param  array<string, mixed>  $details  human-readable facts about the action
     * @param  list<string>  $editableFields  arguments the human may change before confirming
     */
    public function __construct(
        public string $summary,
        public array $arguments,
        public array $details = [],
        public array $editableFields = [],
    ) {}
}
