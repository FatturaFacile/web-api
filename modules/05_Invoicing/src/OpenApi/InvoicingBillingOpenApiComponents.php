<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the billing endpoints of the invoicing module: down payments, deferred
 * invoices, billing plans, debit notes, payment schedules and the sales reports.
 */
#[OA\Parameter(parameter: 'InvoicingBillingOrderId', name: 'orderId', description: 'Identifier of the sales order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'InvoicingBillingPlanId', name: 'id', description: 'Identifier of the billing plan', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'InvoicingBillingPlanItemId', name: 'itemId', description: 'Identifier of the billing plan item', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'InvoicingBillingInvoiceId', name: 'id', description: 'Identifier of the sales document', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'InvoicingBillingUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingBillingForbidden', description: 'The role of the user in the company cannot write invoicing documents (read-only roles)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingBillingNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'InvoicingBillingUnprocessable', description: 'Validation error, a source document that cannot be billed, or no open fiscal period / exchange rate for the document date', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Response(response: 'InvoicingBillingIssuedInvoice', description: 'Invoice issued: numbered, posted to the ledger', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource'))]
final class InvoicingBillingOpenApiComponents {}
