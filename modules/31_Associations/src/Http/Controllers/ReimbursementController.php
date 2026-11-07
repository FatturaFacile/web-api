<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\DecisionRequest;
use Modules\Associations\Http\Requests\PaymentDetailsRequest;
use Modules\Associations\Http\Requests\StoreFileRequest;
use Modules\Associations\Http\Requests\StoreReimbursementRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\ReimbursementAttachment;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Services\ReimbursementService;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense and mileage reimbursements of volunteers and members, with proofs, approval and posting to the ledger.
 */
final class ReimbursementController extends AssociationController
{
    public function __construct(private readonly ReimbursementService $reimbursements) {}

    #[AssociationGet('/api/v1/associations/reimbursements', 'listAssociationReimbursements', 'Reimbursements filtered by year, status, person, campaign or project', query: ['year', 'status', 'member_id', 'campaign_id', 'project_id', 'per_page'], response: 'AssociationRecord')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(Reimbursement::query()->with('attachments')
            ->when($request->integer('year') > 0, static fn ($query) => $query->whereYear('incurred_on', $request->integer('year')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', $request->string('member_id')->toString()))
            ->when($request->filled('campaign_id'), static fn ($query) => $query->where('campaign_id', $request->string('campaign_id')->toString()))
            ->when($request->filled('project_id'), static fn ($query) => $query->where('project_id', $request->string('project_id')->toString()))
            ->orderByDesc('incurred_on')
            ->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/reimbursements', 'submitAssociationReimbursement', 'Submit an expense or a journey with an own vehicle; a journey is distance times the unit price given', 'AssociationReimbursementRequest', response: 'AssociationRecord')]
    public function store(StoreReimbursementRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);

        return (new AssociationRecordResource($this->reimbursements->submit($request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/reimbursements/{id}/attachments', 'attachAssociationReimbursementProof', 'Attach a receipt or another proof', 'AssociationFileRequest', multipart: true, response: 'AssociationRecord')]
    public function attach(StoreFileRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $attachment = $this->reimbursements->attach(Reimbursement::query()->findOrFail($id), $request->file('file'));

        return (new AssociationRecordResource($attachment))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/reimbursement-attachments/{id}/download', 'downloadAssociationReimbursementProof', 'Download a proof of a reimbursement', file: true)]
    public function download(string $id, PrivateFiles $files): StreamedResponse
    {
        $this->allow(AssociationCapability::Read);
        $attachment = ReimbursementAttachment::query()->findOrFail($id);

        return $files->download($attachment->disk, $attachment->path, $attachment->original_name);
    }

    #[AssociationPost('/api/v1/associations/reimbursements/{id}/decision', 'decideAssociationReimbursement', 'Approve or reject a reimbursement', 'AssociationDecisionRequest', response: 'AssociationRecord')]
    public function decide(DecisionRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->reimbursements->decide(Reimbursement::query()->findOrFail($id), $request->boolean('approved'), $request->validated('reason'), $this->actorId($request)));
    }

    #[AssociationPost('/api/v1/associations/reimbursements/{id}/pay', 'payAssociationReimbursement', 'Pay an approved reimbursement and post it to the general ledger', 'AssociationPaymentDetailsRequest', response: 'AssociationRecord')]
    public function pay(PaymentDetailsRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->reimbursements->pay(
            Reimbursement::query()->with('campaign')->findOrFail($id),
            CarbonImmutable::parse((string) $request->validated('paid_on')),
            (string) $request->validated('payment_method'),
            $this->actorId($request),
        ));
    }
}
