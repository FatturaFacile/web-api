<?php

declare(strict_types=1);

namespace Modules\Copilot\Contracts;

use Modules\Copilot\DTOs\ProposedActionPreview;
use Modules\Copilot\DTOs\ToolContext;

interface MutatingActionTool extends CopilotTool
{
    /**
     * Dry run: normalises the arguments and describes the action without writing anything.
     *
     * @param  array<string, mixed>  $arguments  validated
     */
    public function preview(ToolContext $context, array $arguments): ProposedActionPreview;

    /**
     * Runs the action through the existing domain action. Called only after a human confirmed.
     *
     * @param  array<string, mixed>  $arguments  validated again after the overrides
     * @return array<string, mixed> outcome (entity id and label) shown back to the human
     */
    public function execute(ToolContext $context, array $arguments): array;
}
