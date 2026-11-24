<?php

declare(strict_types=1);

namespace Modules\Contracts\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the contract register.
 */
#[OA\Tag(name: 'Contract Register', description: 'Customer and supplier contracts with terms, renewal, notice deadlines and alerts')]
#[OA\Schema(
    schema: 'ContractResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['customer', 'supplier']),
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_number', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'counterparty_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'contract_value', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'auto_renew', type: 'boolean'),
        new OA\Property(property: 'notice_period_days', type: 'integer', nullable: true),
        new OA\Property(property: 'renewal_term_months', type: 'integer', nullable: true),
        new OA\Property(property: 'alert_days_before', type: 'integer'),
        new OA\Property(property: 'notice_deadline', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notice_given_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lifecycle_status', type: 'string', nullable: true, enum: ['draft', 'review', 'active', 'renewal', 'expired', 'terminated'], description: '`status` read as a lifecycle status (`pending_approval` is review, `cancelled` is terminated); null for a status outside the lifecycle'),
        new OA\Property(property: 'allowed_transitions', type: 'array', items: new OA\Items(type: 'string'), description: 'Statuses reachable with POST .../transition'),
        new OA\Property(property: 'days_to_notice_deadline', type: 'integer', nullable: true, description: 'Negative once the deadline passed; only for contracts in force'),
        new OA\Property(property: 'notice_given_late', type: 'boolean', description: 'Notice was recorded after the notice deadline, so it may not stop this term'),
    ]
)]
#[OA\Schema(
    schema: 'ContractAlertResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_kind', type: 'string', enum: ['customer', 'supplier']),
        new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'alert_type', type: 'string', enum: ['notice_deadline', 'expiry']),
        new OA\Property(property: 'due_on', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'acknowledged']),
    ]
)]
#[OA\Schema(
    schema: 'StoreContractRequest',
    required: ['kind', 'counterparty_id', 'contract_number', 'title', 'start_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['customer', 'supplier']),
        new OA\Property(property: 'counterparty_id', type: 'string', format: 'uuid', description: 'Contact for a customer contract, supplier for a supplier contract'),
        new OA\Property(property: 'contract_number', type: 'string', maxLength: 60),
        new OA\Property(property: 'title', type: 'string', maxLength: 180),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true, description: 'Required for a supplier contract'),
        new OA\Property(property: 'contract_value', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active'], nullable: true),
        new OA\Property(property: 'auto_renew', type: 'boolean', nullable: true),
        new OA\Property(property: 'notice_period_days', type: 'integer', nullable: true),
        new OA\Property(property: 'renewal_term_months', type: 'integer', nullable: true),
        new OA\Property(property: 'alert_days_before', type: 'integer', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'UpdateContractTermsRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'auto_renew', type: 'boolean'),
        new OA\Property(property: 'notice_period_days', type: 'integer', nullable: true),
        new OA\Property(property: 'renewal_term_months', type: 'integer', nullable: true),
        new OA\Property(property: 'alert_days_before', type: 'integer'),
    ]
)]
#[OA\Schema(schema: 'RenewContractRequest', type: 'object', properties: [new OA\Property(property: 'new_end_date', type: 'string', format: 'date', nullable: true, description: 'Without it the renewal term applies')])]
final class ContractSchemas {}
