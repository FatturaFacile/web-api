<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the quote approval endpoints of the invoicing module.
 */
#[OA\Parameter(parameter: 'InvoicingQuoteId', name: 'id', description: 'Identifier of the quote', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'InvoicingQuoteUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingQuoteForbidden', description: 'Only owners, administrators and sales managers of the company decide on a quote without a workflow', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingQuoteNotFound', description: 'Quote not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingQuoteUnprocessable', description: 'The status of the quote does not allow the operation, or a running approval workflow owns the decision', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class InvoicingOpenApiComponents {}
