<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the quote approval endpoints.
 */
#[OA\Schema(
    schema: 'InvoicingRejectSalesQuoteRequest',
    description: 'Request payload validated by RejectSalesQuoteRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true, description: 'Why the quote is rejected; appended to the notes of the quote.'),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingQuoteApprovalSubmission',
    description: 'A quote submitted for approval, with how the decision will be taken.',
    type: 'object',
    properties: [
        new OA\Property(property: 'quote', ref: '#/components/schemas/InvoicingSalesQuoteResource'),
        new OA\Property(property: 'approval_mode', type: 'string', enum: ['workflow', 'manual'], description: 'workflow: a running approval workflow instance takes the decision; manual: an owner, administrator or sales manager approves or rejects the quote directly.'),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
final class InvoicingQuoteApprovalSchemas {}
