<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Enums\RiskLevel;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Models\SupplierRiskProfile;
use OpenApi\Attributes as OA;

/**
 * Counters for the supplier portal dashboard.
 */
final class SummaryController extends Controller
{
    use InteractsWithSupplierPortal;

    #[OA\Get(path: '/api/v1/supplier-portal/summary', operationId: 'getSupplierPortalSummary', description: 'Onboardings by status, documents waiting for review, documents expiring within 30 days or expired, bank change requests waiting and suppliers by risk level.', summary: 'Supplier portal summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpSummary')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', SupplierOnboarding::class);
        $tenantId = $this->tenantId();

        $onboardings = SupplierOnboarding::query()->where('tenant_id', $tenantId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $risk = SupplierRiskProfile::query()->where('tenant_id', $tenantId)->selectRaw('level, count(*) as total')->groupBy('level')->pluck('total', 'level');

        return response()->json(['data' => [
            'onboardings_by_status' => collect(OnboardingStatus::values())->mapWithKeys(static fn (string $status): array => [$status => (int) ($onboardings[$status] ?? 0)]),
            'documents_to_review' => SupplierDocumentSubmission::query()->where('tenant_id', $tenantId)->where('status', SupplierDocumentSubmission::Submitted)->count(),
            'documents_expiring' => SupplierDocumentSubmission::query()->where('tenant_id', $tenantId)->where('status', SupplierDocumentSubmission::Accepted)->whereNotNull('expires_at')->whereDate('expires_at', '<=', now()->addDays(30)->toDateString())->count(),
            'bank_changes_pending' => BankChangeRequest::query()->where('tenant_id', $tenantId)->where('status', BankChangeRequest::Pending)->count(),
            'suppliers_by_risk' => collect(RiskLevel::values())->mapWithKeys(static fn (string $level): array => [$level => (int) ($risk[$level] ?? 0)]),
        ]]);
    }
}
