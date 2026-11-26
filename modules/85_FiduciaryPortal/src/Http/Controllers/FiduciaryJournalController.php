<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Http\Requests\InspectAccountLedgerRequest;
use Modules\FiduciaryPortal\Http\Requests\VerifyJournalEntriesRequest;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryJournalReviewService;
use OpenApi\Attributes as OA;

final class FiduciaryJournalController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiduciaryJournalReviewService $journalReview,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/journals',
        operationId: 'fiduciaryPortalJournalsIndex',
        summary: 'List Prima Nota journal entries for fiduciary review with filters and transcoding preview',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Filtered journal entries')]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        $result = $this->journalReview->listJournals($tenantId, [
            'fiscal_year_id' => $request->query('fiscal_year_id'),
            'fiscal_period_id' => $request->query('fiscal_period_id'),
            'month' => $request->query('month'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'account_code_from' => $request->query('account_code_from'),
            'account_code_to' => $request->query('account_code_to'),
            'journal_type' => $request->query('journal_type'),
            'status' => $request->query('status'),
            'fiduciary_verified' => $request->query('fiduciary_verified'),
            'has_open_notes' => $request->boolean('has_open_notes'),
            'search' => $request->query('search'),
            'studio_system' => $request->query('studio_system', 'universal'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/journals/verify',
        operationId: 'fiduciaryPortalJournalsVerify',
        summary: 'Mark or unmark single or batch journal entries as Verificato dal Fiduciario',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Verification status updated')]
    )]
    public function verify(VerifyJournalEntriesRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VERIFY);

        /** @var User $actor */
        $actor = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $actor->tenant_id);
        $validated = $request->validated();

        $result = $this->journalReview->verifyEntries(
            tenantId: $tenantId,
            entryIds: array_values(array_map('strval', (array) $validated['entry_ids'])),
            verified: (bool) $validated['verified'],
            note: isset($validated['note']) ? (string) $validated['note'] : null,
            actor: $actor,
        );

        return $this->successResponse($result, __('fiduciary_portal.verification_updated'));
    }

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/account-ledger',
        operationId: 'fiduciaryPortalAccountLedger',
        summary: 'Inspect General Ledger account movements (Mastrino Contabile) with studio transcoding',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Account ledger report')]
    )]
    public function accountLedger(InspectAccountLedgerRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);
        $validated = $request->validated();

        $report = $this->journalReview->inspectAccountLedger(
            tenantId: $tenantId,
            accountId: (string) $validated['account_id'],
            from: (string) $validated['from'],
            to: (string) $validated['to'],
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 100),
            studioSystem: (string) ($validated['studio_system'] ?? 'universal'),
        );

        return $this->successResponse($report);
    }
}
