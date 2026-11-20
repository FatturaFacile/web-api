<?php

declare(strict_types=1);

namespace Modules\Copilot\Contracts;

use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Enums\ToolType;

/**
 * A capability offered to the model. The registry only exposes it to users who hold the permission it names.
 */
interface CopilotTool
{
    public function name(): string;

    public function type(): ToolType;

    /**
     * Module of config/permissions.php the caller needs access to.
     */
    public function module(): string;

    /**
     * read for queries, write for actions.
     */
    public function access(): string;

    public function description(): string;

    /**
     * JSON Schema of the arguments, for the model. Computed per caller (an enum may list only what the caller may use).
     *
     * @return array<string, mixed>
     */
    public function schema(ToolContext $context): array;

    /**
     * Laravel validation rules of the arguments, scoped to the tenant. The model's arguments are untrusted input.
     *
     * @return array<string, mixed>
     */
    public function rules(ToolContext $context): array;
}
