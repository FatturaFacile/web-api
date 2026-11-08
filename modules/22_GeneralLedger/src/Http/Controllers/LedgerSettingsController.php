<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\UpdateLedgerSettingsRequest;
use Modules\GeneralLedger\Services\LedgerSettingsService;
use OpenApi\Attributes as OA;

/**
 * Ledger settings of the tenant: the default posting accounts other modules read through PostingAccountResolver,
 * and the four-eyes policy of manual journals.
 */
final class LedgerSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerSettingsService $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The settings with the account resolved for each role and where it comes from.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/settings',
        operationId: 'getGeneralLedgerSettings',
        description: 'Default posting accounts of the tenant by role (sales revenue, purchase expense, output and input VAT, receivables, payables, retained earnings, unrealised exchange gain and loss, rounding) and the four-eyes policy of manual journals. For each role: the account and its source, `settings` when chosen here, `preferences` when taken from the account code of the tenant preferences (accounting.accounts.*, banking.ledger_defaults.* for exchange differences), null when the tenant has none: no account code is ever assumed.',
        summary: 'Get ledger settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Ledger settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerSettingsResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        return $this->successResponse($this->settings->show((string) $this->tenantContext->getTenantId()));
    }

    /**
     * Changes the settings sent.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/settings',
        operationId: 'updateGeneralLedgerSettings',
        description: 'Changes the default posting accounts and the four-eyes policy. Only the keys sent change; a role set to null goes back to the preference code, if any. Each account must belong to the tenant and have a type that fits the role (for example revenue for sales_revenue, equity for retained_earnings). With `manual_journal_approval_required` true, every manual journal (or only those whose total reaches `manual_journal_approval_threshold`) waits for the approval of a second person before posting. Postings already made do not change.',
        summary: 'Update ledger settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerUpdateSettingsRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerSettingsResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown role, account of another tenant or of a type that does not fit the role', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateLedgerSettingsRequest $request): JsonResponse
    {
        return $this->successResponse($this->settings->update((string) $this->tenantContext->getTenantId(), $request->validated()), 'Ledger settings updated');
    }
}
