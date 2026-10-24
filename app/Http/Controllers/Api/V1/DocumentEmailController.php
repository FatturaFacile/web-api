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
use Symfony\Component\HttpFoundation\Response;

class DocumentEmailController extends Controller
{
    use ApiResponse;

    /**
     * Email a sales document (invoice, quote, order, delivery note, work report) to its contact or a given address.
     */
    public function store(SendDocumentEmailRequest $request, QueueDocumentEmail $queueEmail, string $type, string $id): JsonResponse
    {
        $document = $this->resolve($type, $id);
        $email = $queueEmail->execute($document, $request->validated('to'), $request->validated('message'));

        return $this->successResponse(new CatalogResource($email), __('documents.messages.email_queued'), Response::HTTP_ACCEPTED);
    }

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
