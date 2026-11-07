<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\AssignFeeRequest;
use Modules\Associations\Http\Requests\RecordPaymentRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Receipt;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\FeePaymentService;
use Modules\Associations\Services\FeeService;
use Modules\Associations\Services\FeeStandingService;
use Modules\Associations\Services\PaymentLinkService;
use Modules\Associations\Services\ReceiptService;
use Modules\Associations\Support\AssociationCapability;

/**
 * What participants owe and what they paid.
 */
final class FeeController extends AssociationController
{
    public function __construct(
        private readonly FeeService $fees,
        private readonly FeePaymentService $payments,
        private readonly PaymentLinkService $paymentLinks,
        private readonly FeeStandingService $standings,
        private readonly ReceiptService $receipts,
    ) {}

    #[AssociationGet('/api/v1/associations/fee-assignments', 'listAssociationFeeAssignments', 'List the fees assigned', query: ['member_id', 'course_id', 'status'])]
    public function assignments(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Finance);

        $assignments = FeeAssignment::query()->with('instalments')
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', (string) $request->query('member_id')))
            ->when($request->filled('course_id'), static fn ($query) => $query->where('course_id', (string) $request->query('course_id')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($assignments);
    }

    #[AssociationPost('/api/v1/associations/fee-assignments', 'assignAssociationFee', 'Assign a fee plan to a person, with its instalments', 'AssociationFeeAssignmentRequest')]
    public function assign(AssignFeeRequest $request): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->fees->assign(
            Member::query()->findOrFail($request->validated('member_id')),
            FeePlan::query()->findOrFail($request->validated('fee_plan_id')),
            $request->safe()->except(['member_id', 'fee_plan_id']),
        ));
    }

    #[AssociationPost('/api/v1/associations/fee-assignments/{id}/cancel', 'cancelAssociationFee', 'Cancel a fee and its open instalments')]
    public function cancel(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->fees->cancel(FeeAssignment::query()->findOrFail($id)));
    }

    #[AssociationGet('/api/v1/associations/members/{id}/fee-standing', 'showAssociationFeeStanding', 'Show the fee state of a person, overall or for a course', query: ['course_id'])]
    public function standing(Request $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $courseId = $request->query('course_id');
        $standing = $this->standings->of(Member::query()->findOrFail($id), is_string($courseId) ? $courseId : null);

        return response()->json(['data' => ['standing' => $standing, 'color' => $standing->color()]]);
    }

    #[AssociationGet('/api/v1/associations/instalments', 'listAssociationInstalments', 'List the instalments', query: ['status', 'due_before', 'member_id'])]
    public function instalments(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Finance);

        $instalments = FeeInstalment::query()->with('assignment')
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('due_before'), static fn ($query) => $query->whereDate('due_on', '<=', (string) $request->query('due_before')))
            ->when($request->filled('member_id'), static fn ($query) => $query->whereHas('assignment', static fn ($inner) => $inner->where('member_id', (string) $request->query('member_id'))))
            ->orderBy('due_on')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($instalments);
    }

    #[AssociationPost('/api/v1/associations/instalments/{id}/payments', 'recordAssociationPayment', 'Record a payment: receipt and accounting through the payment flow of the company', 'AssociationPaymentRequest')]
    public function pay(RecordPaymentRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->payments->record(FeeInstalment::query()->findOrFail($id), $request->validated(), $this->actorId($request)));
    }

    #[AssociationPost('/api/v1/associations/instalments/{id}/payment-link', 'createAssociationPaymentLink', 'Create an online payment link for an instalment')]
    public function paymentLink(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $result = $this->paymentLinks->create(FeeInstalment::query()->with('assignment')->findOrFail($id));

        return response()->json(['data' => ['url' => $result['url'], 'reference' => $result['link']->provider_reference, 'amount' => $result['link']->amount, 'expires_at' => $result['link']->expires_at]], 201);
    }

    #[AssociationGet('/api/v1/associations/receipts', 'listAssociationReceipts', 'List the receipts', query: ['member_id', 'fiscal_year'])]
    public function listReceipts(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Finance);

        $receipts = Receipt::query()
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', (string) $request->query('member_id')))
            ->when($request->filled('fiscal_year'), static fn ($query) => $query->where('fiscal_year', $request->integer('fiscal_year')))
            ->orderByDesc('fiscal_year')
            ->orderByDesc('sequential_number')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($receipts);
    }

    #[AssociationGet('/api/v1/associations/receipts/{id}/pdf', 'downloadAssociationReceipt', 'Download a receipt as PDF', file: true)]
    public function receiptPdf(string $id): Response
    {
        $this->allow(AssociationCapability::Finance);
        $receipt = Receipt::query()->findOrFail($id);

        return $this->pdf($this->receipts->render($receipt), "receipt-{$receipt->number}.pdf");
    }
}
