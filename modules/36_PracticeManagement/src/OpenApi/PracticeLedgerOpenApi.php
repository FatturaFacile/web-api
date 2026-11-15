<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the expenses, client funds ledger, conflicts check and fee proposal endpoints.
 */
#[OA\Schema(schema: 'PracticeExpenseRequest', type: 'object', required: ['expense_date', 'category', 'description', 'amount'], properties: [
    new OA\Property(property: 'legal_case_id', description: 'Required when creating; cannot be changed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'expense_date', type: 'string', format: 'date'),
    new OA\Property(property: 'category', type: 'string', enum: ['court_fee', 'travel', 'filing', 'expert', 'courier', 'other']),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'amount', type: 'string', example: '120.50'),
    new OA\Property(property: 'is_billable', type: 'boolean'),
])]
#[OA\Schema(schema: 'PracticeTrustTransactionRequest', type: 'object', required: ['type', 'client_id', 'occurred_on', 'amount'], properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['deposit', 'withdrawal', 'fee_transfer']),
    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'legal_case_id', description: 'Matter of the client the funds are held for; omit for funds held for the client in general', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'occurred_on', type: 'string', format: 'date'),
    new OA\Property(property: 'amount', type: 'string', example: '2500.00'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeConflictCheckRequest', type: 'object', required: ['name', 'role'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'role', type: 'string', enum: ['client', 'opposing_party', 'related_party']),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'PracticeCasePartyRequest', type: 'object', required: ['legal_case_id', 'name', 'role'], properties: [
    new OA\Property(property: 'legal_case_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'role', type: 'string', enum: ['client', 'opposing_party', 'related_party', 'witness']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeFeeProposalRequest', type: 'object', required: ['client_id', 'title', 'fee_type'], properties: [
    new OA\Property(property: 'client_id', description: 'Required when creating', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'scope', type: 'string', nullable: true),
    new OA\Property(property: 'fee_type', type: 'string', enum: ['hourly', 'fixed']),
    new OA\Property(property: 'hourly_rate', description: 'Required for hourly proposals', type: 'string', nullable: true),
    new OA\Property(property: 'estimated_hours', description: 'Required for hourly proposals', type: 'string', nullable: true),
    new OA\Property(property: 'fixed_fee', description: 'Required for fixed proposals', type: 'string', nullable: true),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'PracticeFeeProposalAcceptRequest', type: 'object', required: ['conflict_check_id'], properties: [
    new OA\Property(property: 'conflict_check_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'override_reason', description: 'Required when the conflicts check found a conflict', type: 'string', nullable: true),
])]
final class PracticeLedgerOpenApi {}
