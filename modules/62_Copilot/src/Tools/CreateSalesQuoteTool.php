<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Modules\Copilot\Contracts\MutatingActionTool;
use Modules\Copilot\DTOs\ProposedActionPreview;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Enums\ToolType;
use Modules\Copilot\Tools\Concerns\HandlesDocumentLines;
use Modules\Invoicing\Actions\CreateSalesQuoteAction;

/**
 * Stages a sales quote; CreateSalesQuoteAction runs only after a human confirmed the card.
 */
final readonly class CreateSalesQuoteTool implements MutatingActionTool
{
    use HandlesDocumentLines;

    public function __construct(private CreateSalesQuoteAction $action) {}

    public function name(): string
    {
        return 'create_sales_quote';
    }

    public function type(): ToolType
    {
        return ToolType::MutatingAction;
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function access(): string
    {
        return 'write';
    }

    public function description(): string
    {
        return 'Prepare a sales quote for a customer. Nothing is created until the user confirms the proposed card.';
    }

    public function schema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => $this->documentSchemaProperties() + ['valid_until' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD']],
            'required' => ['contact_id', 'items'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return $this->documentRules($context->tenantId) + [
            'valid_until' => ['nullable', 'date'],
        ];
    }

    public function preview(ToolContext $context, array $arguments): ProposedActionPreview
    {
        $totals = $this->documentTotals($arguments);

        return new ProposedActionPreview(
            summary: sprintf('Create a quote for %s, %d line(s), total %s %s', $totals['details']['customer'], $totals['details']['line_count'], $totals['details']['total_gross'], $totals['details']['currency']),
            arguments: $arguments,
            details: $totals['details'] + ['valid_until' => $arguments['valid_until'] ?? null],
            editableFields: [...$this->lineEditablePaths($arguments), 'valid_until'],
        );
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $items = array_values((array) $arguments['items']);
        unset($arguments['items']);

        $quote = $this->action->execute($arguments + ['tenant_id' => $context->tenantId, 'created_by' => $context->user->id], $items);

        return [
            'entity_type' => 'quotes',
            'entity_id' => (string) $quote->id,
            'label' => '#Quote-'.$quote->quote_number,
            'route' => '/quotes/'.$quote->id,
        ];
    }
}
