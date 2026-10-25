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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ContactInsightsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContactInsights $insights) {}

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
