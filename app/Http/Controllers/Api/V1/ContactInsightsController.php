<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\QueueAccountStatement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StatementRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Services\Crm\ContactInsights;
use App\Services\Crm\Customer360Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ContactInsightsController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(private readonly ContactInsights $insights, private readonly Customer360Service $customer360) {}

    #[OA\Get(
        path: '/api/v1/crm/contacts/{id}/overview',
        operationId: 'getContactOverview',
        description: '360-degree customer card: open balance, credit limit and headroom, quotes, orders in progress, delivery notes to invoice, invoices, open tickets and recent interactions. Pass group=1 to consolidate the whole corporate group.',
        summary: 'Get customer 360 overview',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'group', in: 'query', required: false, description: 'Aggregate the contact and all its subsidiaries', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Overview retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function overview(Request $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('view', $contact);

        return $this->successResponse($this->customer360->overview($contact, $request->boolean('group')), 'Customer overview retrieved');
    }

    #[OA\Get(
        path: '/api/v1/crm/contacts/{id}/statistics',
        operationId: 'getContactStatistics',
        description: 'Get financial statistics and turnover metrics for a contact.',
        summary: 'Get contact statistics',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statistics retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function statistics(string $id): JsonResponse
    {
        return $this->successResponse($this->insights->statistics(Contact::findOrFail($id)), __('contacts.messages.statistics'));
    }

    /**
     * Open-items account statement as of a date (default: today).
     */
    #[OA\Get(
        path: '/api/v1/crm/contacts/{id}/statement',
        operationId: 'getContactStatement',
        description: 'Open-items account statement for a contact as of a given date.',
        summary: 'Get contact statement',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'as_of', in: 'query', required: false, description: 'Cutoff date', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function statement(StatementRequest $request, string $id): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->validated('as_of')) : null;

        return $this->successResponse($this->insights->statement(Contact::findOrFail($id), $asOf), __('contacts.messages.statement'));
    }

    #[OA\Post(
        path: '/api/v1/crm/contacts/{id}/statement/email',
        operationId: 'emailContactStatement',
        description: 'Send open-items account statement PDF to customer email address.',
        summary: 'Email contact statement',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'to', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'as_of', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Statement email queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function emailStatement(StatementRequest $request, QueueAccountStatement $queueStatement, string $id): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->validated('as_of')) : now();
        $email = $queueStatement->execute(Contact::findOrFail($id), $request->validated('to'), $asOf);

        return $this->successResponse(new CatalogResource($email), __('contacts.messages.statement_queued'), Response::HTTP_ACCEPTED);
    }
}
