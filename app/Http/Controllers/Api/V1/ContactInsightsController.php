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
use Symfony\Component\HttpFoundation\Response;

class ContactInsightsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContactInsights $insights) {}

    public function statistics(string $id): JsonResponse
    {
        return $this->successResponse($this->insights->statistics(Contact::findOrFail($id)), __('contacts.messages.statistics'));
    }

    /**
     * Open-items account statement as of a date (default: today).
     */
    public function statement(StatementRequest $request, string $id): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->validated('as_of')) : null;

        return $this->successResponse($this->insights->statement(Contact::findOrFail($id), $asOf), __('contacts.messages.statement'));
    }

    public function emailStatement(StatementRequest $request, QueueAccountStatement $queueStatement, string $id): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->validated('as_of')) : now();
        $email = $queueStatement->execute(Contact::findOrFail($id), $request->validated('to'), $asOf);

        return $this->successResponse(new CatalogResource($email), __('contacts.messages.statement_queued'), Response::HTTP_ACCEPTED);
    }
}
