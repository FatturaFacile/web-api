<?php

declare(strict_types=1);

namespace Modules\Copilot\Contracts;

use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\DTOs\ToolResult;

interface ReadQueryTool extends CopilotTool
{
    /**
     * @param  array<string, mixed>  $arguments  validated
     */
    public function run(ToolContext $context, array $arguments): ToolResult;
}
