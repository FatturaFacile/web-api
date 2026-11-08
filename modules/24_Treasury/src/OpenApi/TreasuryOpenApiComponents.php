<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses of the Treasury module. They live apart from the schemas: swagger-php drops
 * every schema of a class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'TreasuryBankAccountId', name: 'id', description: 'Bank account identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'TreasuryCashRegisterId', name: 'id', description: 'Cash register identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'TreasuryProposalId', name: 'id', description: 'Payment proposal identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'TreasuryRecurringItemId', name: 'id', description: 'Recurring cash item identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'TreasuryPerPage', name: 'per_page', description: 'Items per page (max 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'TreasuryFromDate', name: 'from_date', description: 'First day (YYYY-MM-DD)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'TreasuryToDate', name: 'to_date', description: 'Last day (YYYY-MM-DD)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Response(response: 'TreasuryUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TreasuryForbidden', description: 'The role of the user does not allow the action (read-only roles cannot write)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TreasuryNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TreasuryValidationError', description: 'Validation error, a ledger account not configured, a closed fiscal period or a state that does not allow the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class TreasuryOpenApiComponents {}
