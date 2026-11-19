<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Actions\SendAndSignJobOfferAction;
use Modules\HumanResources\DTOs\SendJobOfferDTO;
use Modules\HumanResources\Http\Requests\Recruiting\DeclineJobOfferRequest;
use Modules\HumanResources\Http\Requests\Recruiting\MarkJobOfferSignedRequest;
use Modules\HumanResources\Http\Requests\Recruiting\StoreJobOfferRequest;
use Modules\HumanResources\Http\Resources\JobOfferLetterResource;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOffer;
use OpenApi\Attributes as OA;

final class JobOfferController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SendAndSignJobOfferAction $offerAction
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/applications/{id}/offer',
        operationId: 'hrRecruitingGetApplicationOffer',
        description: 'The current job offer letter of the application; data is null when none exists.',
        summary: 'Get current job offer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Job offer or null', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', nullable: true)])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);
        $this->authorize('view', $application);

        $offer = $application->currentOffer()->first();

        return response()->json(['data' => $offer !== null ? (new JobOfferLetterResource($offer))->resolve($request) : null]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/offer',
        operationId: 'hrRecruitingCreateApplicationOffer',
        description: 'Generates the offer letter and sends it to the candidate (send defaults to true).',
        summary: 'Create job offer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['proposed_salary', 'start_date'], properties: [new OA\Property(property: 'proposed_salary', type: 'number', minimum: 0), new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF'], nullable: true), new OA\Property(property: 'start_date', type: 'string', format: 'date'), new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal'], nullable: true), new OA\Property(property: 'contract_type', type: 'string', enum: ['permanent', 'fixed_term', 'apprenticeship', 'contractor', 'internship'], nullable: true), new OA\Property(property: 'weekly_hours', type: 'number', minimum: 1, maximum: 60, nullable: true), new OA\Property(property: 'probation_months', type: 'integer', minimum: 0, maximum: 12, nullable: true), new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'send', type: 'boolean', nullable: true, description: 'Defaults to true')])),
        responses: [
            new OA\Response(response: 201, description: 'Job offer created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(StoreJobOfferRequest $request, string $id): JsonResponse
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);
        $this->authorize('update', $application);

        $offer = $this->offerAction->execute(new SendJobOfferDTO(
            applicationId: (string) $application->id,
            proposedSalary: (float) $request->input('proposed_salary'),
            startDate: (string) $request->input('start_date'),
            currency: $request->filled('currency') ? (string) $request->input('currency') : null,
            employmentType: $request->filled('employment_type') ? (string) $request->input('employment_type') : null,
            contractType: $request->filled('contract_type') ? (string) $request->input('contract_type') : null,
            weeklyHours: $request->filled('weekly_hours') ? (float) $request->input('weekly_hours') : null,
            probationMonths: $request->filled('probation_months') ? (int) $request->input('probation_months') : null,
            validUntil: $request->filled('valid_until') ? (string) $request->input('valid_until') : null,
            send: $request->has('send') && $request->input('send') !== null ? $request->boolean('send') : true,
            actorUserId: $this->actorId($request)
        ));

        return response()->json(['data' => (new JobOfferLetterResource($offer->refresh()))->resolve($request)], 201);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/offers/{id}/mark-signed',
        operationId: 'hrRecruitingMarkOfferSigned',
        description: 'Records that the candidate signed the offer.',
        summary: 'Mark job offer signed',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job offer', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'signed_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Job offer', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function markSigned(MarkJobOfferSignedRequest $request, string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        $offer = $this->offerAction->markSigned($offer, $request->filled('signed_at') ? (string) $request->input('signed_at') : null);

        return response()->json(['data' => (new JobOfferLetterResource($offer))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/offers/{id}/decline',
        operationId: 'hrRecruitingDeclineOffer',
        description: 'Records that the candidate declined the offer.',
        summary: 'Record job offer declined',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job offer', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Job offer', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function decline(DeclineJobOfferRequest $request, string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        $offer = $this->offerAction->decline($offer, $request->filled('note') ? (string) $request->input('note') : null);

        return response()->json(['data' => (new JobOfferLetterResource($offer))->resolve($request)]);
    }

    private function findOffer(string $id): JobOffer
    {
        /** @var JobOffer $offer */
        $offer = JobOffer::query()->findOrFail($id);

        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($offer->application_id);
        $this->authorize('update', $application);

        return $offer;
    }
}
