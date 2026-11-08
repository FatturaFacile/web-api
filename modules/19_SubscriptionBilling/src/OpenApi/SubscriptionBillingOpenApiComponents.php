<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Tags, parameters and responses shared by the endpoints of the subscription engine.
 */
#[OA\Tag(name: 'Subscriptions Contracts', description: 'Subscription contracts: lifecycle, prorated changes, cancellations, billing, invoices and dunning')]
#[OA\Tag(name: 'Subscriptions Plans', description: 'Plans and price tiers (flat, per seat, tiered, volume, metered)')]
#[OA\Tag(name: 'Subscriptions Usage', description: 'Metered usage records')]
#[OA\Tag(name: 'Subscriptions Billing Runs', description: 'Recurring billing runs through the invoicing services')]
#[OA\Tag(name: 'Subscriptions Revenue Recognition', description: 'IFRS 15 revenue contracts and the monthly recognition posted to the General Ledger')]
#[OA\Tag(name: 'Subscriptions Metrics', description: 'MRR, ARR, churn, LTV and the cash forecast')]
#[OA\Tag(name: 'Subscriptions Dunning', description: 'Dunning policy and runs')]
#[OA\Parameter(parameter: 'SubscriptionBillingContractId', name: 'id', description: 'Identifier of the subscription contract', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SubscriptionBillingPlanId', name: 'id', description: 'Identifier of the plan', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SubscriptionBillingRunId', name: 'id', description: 'Identifier of the billing run', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SubscriptionBillingRevenueContractId', name: 'id', description: 'Identifier of the revenue contract', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SubscriptionBillingPortalToken', name: 'token', description: 'Customer portal magic-link token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'SubscriptionBillingPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
#[OA\Response(response: 'SubscriptionBillingUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SubscriptionBillingForbidden', description: 'The role of the user does not allow this access to the subscriptions module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SubscriptionBillingNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SubscriptionBillingUnprocessable', description: 'Validation error or a transition the status does not allow', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Response(response: 'SubscriptionBillingContract', description: 'The subscription contract', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')]))]
#[OA\Response(response: 'SubscriptionBillingPlan', description: 'The plan', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingPlanResource')]))]
final class SubscriptionBillingOpenApiComponents {}
