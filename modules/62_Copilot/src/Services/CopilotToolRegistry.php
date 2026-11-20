<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Copilot\Contracts\CopilotTool;
use Modules\Copilot\DTOs\ToolContext;

/**
 * The governed list of what the model may use. A tool is exposed only to a caller whose role (or granular grant) reaches the
 * module and access level the tool names, so the model of a user without payroll access never even learns the payroll
 * tools exist; a call to a tool that was not exposed is refused the same way as an unknown one.
 */
final class CopilotToolRegistry
{
    /**
     * @param  list<CopilotTool>  $tools
     */
    public function __construct(
        private readonly ModuleAccessGate $gate,
        private readonly array $tools,
    ) {}

    /**
     * @return list<CopilotTool>
     */
    public function availableTo(ToolContext $context): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (CopilotTool $tool): bool => $this->gate->allows($context->user, $context->tenantId, $tool->module(), $tool->access()),
        ));
    }

    public function find(string $name, ToolContext $context): ?CopilotTool
    {
        foreach ($this->availableTo($context) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * The tool definitions handed to the model.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(ToolContext $context): array
    {
        return array_map(static fn (CopilotTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->schema($context),
        ], $this->availableTo($context));
    }

    /**
     * The arguments of the model are untrusted input: only what the rules of the tool declare gets through.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(CopilotTool $tool, ToolContext $context, array $arguments): array
    {
        return Validator::make($arguments, $tool->rules($context))->validate();
    }
}
