<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Modules\Copilot\Contracts\MutatingActionTool;
use Modules\Copilot\DTOs\ProposedActionPreview;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Enums\ToolType;
use Modules\Copilot\Tools\Concerns\HandlesDocumentLines;
use Modules\Invoicing\Services\InvoicingService;

/**
 * Stages a draft sales invoice. Only a draft is ever created: issuing the invoice stays a separate human decision in the
 * invoicing screens.
 */
final readonly class CreateInvoiceDraftTool implements MutatingActionTool
{
    use HandlesDocumentLines;

    public function __construct(private InvoicingService $invoicing) {}

    public function name(): string
    {
        return 'create_invoice_draft';
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
        return 'Prepare a DRAFT sales invoice for a customer. Nothing is created until the user confirms the proposed card, and the draft is never issued.';
    }

    public function schema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => $this->documentSchemaProperties() + ['due_date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD']],
            'required' => ['contact_id', 'items'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return $this->documentRules($context->tenantId) + [
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function preview(ToolContext $context, array $arguments): ProposedActionPreview
    {
        $totals = $this->documentTotals($arguments);

        return new ProposedActionPreview(
            summary: sprintf('Create a draft invoice for %s, %d line(s), total %s %s', $totals['details']['customer'], $totals['details']['line_count'], $totals['details']['total_gross'], $totals['details']['currency']),
            arguments: $arguments,
            details: $totals['details'] + ['due_date' => $arguments['due_date'] ?? null],
            editableFields: [...$this->lineEditablePaths($arguments), 'due_date'],
        );
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $items = array_values((array) $arguments['items']);
        unset($arguments['items']);

        $invoice = $this->invoicing->createInvoice($arguments + ['tenant_id' => $context->tenantId], $items);

        return [
            'entity_type' => 'invoices',
            'entity_id' => (string) $invoice->id,
            'label' => '#Invoice-'.$invoice->invoice_number,
            'route' => '/invoices/'.$invoice->id,
        ];
    }
}
