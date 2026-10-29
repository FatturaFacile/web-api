<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GeneralLedgerJournalEntry',
    title: 'General Ledger Journal Entry',
    description: 'Double-entry balanced accounting journal transaction with audit provenance and statutory compliance',
    required: ['id', 'tenant_id', 'entry_number', 'posting_date', 'fiscal_period_id', 'ledger_type', 'journal_type', 'status', 'total_amount']
)]
class GeneralLedgerOpenApiSchemas
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $tenant_id;

    #[OA\Property(example: 'JE-2026-0001')]
    public string $entry_number;

    #[OA\Property(format: 'date', example: '2026-03-31')]
    public string $posting_date;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d')]
    public string $fiscal_period_id;

    #[OA\Property(example: 'statutory', enum: ['statutory', 'ifrs', 'management'])]
    public string $ledger_type;

    #[OA\Property(example: 'standard', enum: ['standard', 'reversing', 'closing', 'opening', 'recurring', 'adjusting'])]
    public string $journal_type;

    #[OA\Property(example: 'posted', enum: ['draft', 'pending_approval', 'posted', 'reversed'])]
    public string $status;

    #[OA\Property(example: 15420.50)]
    public float $total_amount;

    #[OA\Property(example: 'Vendor Invoice & Input VAT Posting')]
    public string $description;

    #[OA\Property(example: 'vendor_invoice')]
    public ?string $source_document_type;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e')]
    public ?string $source_document_id;
}
