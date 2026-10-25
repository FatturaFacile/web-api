<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\QueueDocumentEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SendDocumentEmailRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\DocumentEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class DocumentEmailController extends Controller
{
    use ApiResponse;

    /**
     * Email a sales document (invoice, quote, order, delivery note, work report) to its contact or a given address.
     */
    #[OA\Post(
        path: '/api/v1/documents/{type}/{id}/email',
        operationId: 'emailDocument',
        description: 'Email a sales document (invoice, quote, order, delivery note, work report) with PDF attached to its recipient contact or a custom email.',
        summary: 'Send document via email',
        security: [['bearerAuth' => []]],
        tags: ['Documents', 'Emails'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, description: 'Document type (invoice, quote, order, delivery_note, work_order)', schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'order', 'delivery_note', 'work_order'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Document UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'to', type: 'string', format: 'email', nullable: true, example: 'recipient@example.com'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, example: 'Please find attached invoice INV-2026-0001.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 202, description: 'Email queued successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Document not found or unsupported type', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(SendDocumentEmailRequest $request, QueueDocumentEmail $queueEmail, string $type, string $id): JsonResponse
    {
        $document = $this->resolve($type, $id);
        $email = $queueEmail->execute($document, $request->validated('to'), $request->validated('message'));

        return $this->successResponse(new CatalogResource($email), __('documents.messages.email_queued'), Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/v1/documents/{type}/{id}/emails',
        operationId: 'getDocumentEmailHistory',
        description: 'Get history of emails sent for this document.',
        summary: 'Get document email history',
        security: [['bearerAuth' => []]],
        tags: ['Documents', 'Emails'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, description: 'Document type (invoice, quote, order, delivery_note, work_order)', schema: new OA\Schema(type: 'string', enum: ['invoice', 'quote', 'order', 'delivery_note', 'work_order'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Document UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Email history retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Document not found or unsupported type', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $type, string $id): JsonResponse
    {
        $document = $this->resolve($type, $id);

        $emails = DocumentEmail::where('emailable_type', $document->getMorphClass())
            ->where('emailable_id', $document->getKey())
            ->latest()
            ->get();

        return $this->successResponse(CatalogResource::collection($emails), __('documents.messages.email_history'));
    }

    private function resolve(string $type, string $id): Model
    {
        $class = DocumentEmail::TARGET_TYPES[$type] ?? null;

        abort_if($class === null, Response::HTTP_NOT_FOUND, __('documents.unknown_type'));

        return $class::findOrFail($id);
    }
}
