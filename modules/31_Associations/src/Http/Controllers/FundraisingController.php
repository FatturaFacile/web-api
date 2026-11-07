<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Http\Requests\ReasonRequest;
use Modules\Associations\Http\Requests\RecordDonationRequest;
use Modules\Associations\Http\Requests\RecordExpenseRequest;
use Modules\Associations\Http\Requests\RecordFivePerMilleRequest;
use Modules\Associations\Http\Requests\StoreFileRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\DonationReceipt;
use Modules\Associations\Models\ExpenseCategory;
use Modules\Associations\Models\FivePerMilleAllocation;
use Modules\Associations\Models\SponsorContract;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\FundraisingService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Donations and their receipts, campaigns with a separate account, the 5x1000 and sponsorship contracts. Donors,
 * campaigns, sponsors, contracts and expense categories are maintained through the catalogs.
 */
final class FundraisingController extends AssociationController
{
    public function __construct(private readonly FundraisingService $fundraising) {}

    #[AssociationGet('/api/v1/associations/donations', 'listAssociationDonations', 'Donations filtered by year, campaign or donor', query: ['year', 'campaign_id', 'donor_id', 'per_page'], response: 'AssociationRecord')]
    public function donations(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(Donation::query()->with('receipts')
            ->when($request->integer('year') > 0, static fn ($query) => $query->whereYear('received_on', $request->integer('year')))
            ->when($request->filled('campaign_id'), static fn ($query) => $query->where('campaign_id', $request->string('campaign_id')->toString()))
            ->when($request->filled('donor_id'), static fn ($query) => $query->where('donor_id', $request->string('donor_id')->toString()))
            ->orderByDesc('received_on')
            ->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/donations', 'recordAssociationDonation', 'Record a donation of money or in kind; money is posted to the ledger on the campaign cost centre', 'AssociationDonationRequest', response: 'AssociationRecord')]
    public function recordDonation(RecordDonationRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return (new AssociationRecordResource($this->fundraising->recordDonation($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/donations/{id}/receipt', 'issueAssociationDonationReceipt', 'Issue the numbered receipt of a donation', response: 'AssociationRecord')]
    public function issueReceipt(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $receipt = $this->fundraising->issueReceipt(Donation::query()->with(['donor', 'campaign'])->findOrFail($id));

        return (new AssociationRecordResource($receipt))->additional(['meta' => ['tax_effects_not_stated' => true]])->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/donation-receipts/{id}/cancel', 'cancelAssociationDonationReceipt', 'Cancel a receipt with a reason; it keeps its number', 'AssociationRequiredReasonRequest', response: 'AssociationRecord')]
    public function cancelReceipt(ReasonRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->fundraising->cancelReceipt(DonationReceipt::query()->findOrFail($id), (string) $request->validated('reason')));
    }

    #[AssociationGet('/api/v1/associations/donation-receipts/{id}/pdf', 'printAssociationDonationReceipt', 'A donation receipt as PDF', file: true)]
    public function receiptPdf(string $id): Response
    {
        $this->allow(AssociationCapability::Read);
        $receipt = DonationReceipt::query()->findOrFail($id);

        return $this->pdf($this->fundraising->receiptPdf($receipt), "donation-{$receipt->number}.pdf");
    }

    #[AssociationPost('/api/v1/associations/campaigns/{id}/expenses', 'recordAssociationCampaignExpense', 'Record and post a cost of a campaign', 'AssociationExpenseRequest', response: 'AssociationRecord')]
    public function campaignExpense(RecordExpenseRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $expense = $this->fundraising->recordCampaignExpense(Campaign::query()->findOrFail($id), $request->safe()->except('category_id'), $this->actorId($request));

        return (new AssociationRecordResource($expense))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/campaigns/{id}/report', 'showAssociationCampaignReport', 'The separate account of a campaign: donations, costs, reimbursements and result', response: 'AssociationCampaignReport')]
    public function campaignReport(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->fundraising->campaignReport(Campaign::query()->findOrFail($id))]);
    }

    #[AssociationGet('/api/v1/associations/five-per-mille', 'listAssociationFivePerMille', 'Allocations of the 5x1000 received', query: ['per_page'], response: 'AssociationRecord')]
    public function fivePerMille(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(FivePerMilleAllocation::query()->orderByDesc('received_on')->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/five-per-mille', 'recordAssociationFivePerMille', 'Record an allocation of the 5x1000 received', 'AssociationFivePerMilleRequest', response: 'AssociationRecord')]
    public function recordFivePerMille(RecordFivePerMilleRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return (new AssociationRecordResource($this->fundraising->recordFivePerMille($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/five-per-mille/{id}/expenses', 'recordAssociationFivePerMilleExpense', 'Classify an expense paid with the 5x1000; the total cannot exceed the allocation', 'AssociationExpenseRequest', response: 'AssociationRecord')]
    public function fivePerMilleExpense(RecordExpenseRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        if (! $request->filled('category_id') || ExpenseCategory::query()->whereKey($request->string('category_id')->toString())->where('scope', ExpenseCategory::FivePerMille)->doesntExist()) {
            throw new AssociationException(__('association_finance.five_per_mille_category_required'));
        }

        $expense = $this->fundraising->recordFivePerMilleExpense(FivePerMilleAllocation::query()->findOrFail($id), $request->safe()->only(['incurred_on', 'description', 'amount', 'category_id']));

        return (new AssociationRecordResource($expense))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/five-per-mille/{id}/report', 'showAssociationFivePerMilleReport', 'How an allocation of the 5x1000 was used, by category', response: 'AssociationFivePerMilleReport')]
    public function fivePerMilleReport(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->fundraising->fivePerMilleReport(FivePerMilleAllocation::query()->findOrFail($id)), 'meta' => [
            'categories_configured_by_company' => true,
            'official_form_not_reproduced' => true,
        ]]);
    }

    #[AssociationGet('/api/v1/associations/five-per-mille/{id}/csv', 'exportAssociationFivePerMilleCsv', 'The expenses of an allocation of the 5x1000 as CSV', file: true)]
    public function fivePerMilleCsv(string $id): Response
    {
        $this->allow(AssociationCapability::Read);

        return response($this->fundraising->fivePerMilleCsv(FivePerMilleAllocation::query()->findOrFail($id)), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="five-per-mille.csv"']);
    }

    #[AssociationPost('/api/v1/associations/sponsor-contracts/{id}/document', 'attachAssociationSponsorContract', 'Attach the signed sponsorship contract', 'AssociationFileRequest', multipart: true, response: 'AssociationRecord')]
    public function attachContract(StoreFileRequest $request, string $id, PrivateFiles $files): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);
        $contract = SponsorContract::query()->findOrFail($id);
        $stored = $files->store($request->file('file'), (string) $contract->tenant_id, 'sponsors');
        $contract->update(['disk' => $stored['disk'], 'path' => $stored['path'], 'original_name' => $stored['original_name']]);

        return new AssociationRecordResource($contract);
    }

    #[AssociationGet('/api/v1/associations/sponsor-contracts/{id}/document', 'downloadAssociationSponsorContract', 'Download the signed sponsorship contract', file: true)]
    public function downloadContract(string $id, PrivateFiles $files): StreamedResponse
    {
        $this->allow(AssociationCapability::Read);
        $contract = SponsorContract::query()->findOrFail($id);

        return $files->download($contract->disk, $contract->path, $contract->original_name);
    }
}
