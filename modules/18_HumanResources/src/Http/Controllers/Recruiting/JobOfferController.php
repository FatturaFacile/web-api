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

final class JobOfferController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SendAndSignJobOfferAction $offerAction
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);
        $this->authorize('view', $application);

        $offer = $application->currentOffer()->first();

        return response()->json(['data' => $offer !== null ? (new JobOfferLetterResource($offer))->resolve($request) : null]);
    }

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

    public function markSigned(MarkJobOfferSignedRequest $request, string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        $offer = $this->offerAction->markSigned($offer, $request->filled('signed_at') ? (string) $request->input('signed_at') : null);

        return response()->json(['data' => (new JobOfferLetterResource($offer))->resolve($request)]);
    }

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
