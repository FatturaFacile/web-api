<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\Contracts\RequisitionNotificationGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionApprovalRequestedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionApprovedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionBudgetBlockedMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionBudgetWarningMail;
use App\Infrastructure\Procurement\PurchaseRequisition\Mail\RequisitionRejectedMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mobile\MobilePushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Dispatches localized (it, de, fr, en) Email and Push notifications for Mod 044 Purchase Requisitions.
 */
final readonly class RequisitionNotificationAdapter implements RequisitionNotificationGatewayInterface
{
    /** @var list<string> */
    private const array SupportedLocales = ['en', 'it', 'de', 'fr'];

    public function __construct(
        private MobilePushService $mobilePush,
    ) {}

    public function notifyApproversStepAssigned(
        PurchaseRequisition $requisition,
        WorkflowInstanceStep $step,
        array $tokens,
    ): void {
        if ($tokens === []) {
            return;
        }

        $requester = User::query()->find($requisition->effectiveRequesterId());
        $requesterName = $requester?->name ?? 'User';
        $costCenterLabel = $this->resolveCostCenterLabel($requisition);
        $deliveryDate = $requisition->requested_delivery_date?->toDateString()
            ?? $requisition->required_by_date?->toDateString()
            ?? '-';
        $snapshot = is_array($requisition->budget_check_snapshot) ? $requisition->budget_check_snapshot : [];
        $budgetWarning = (bool) $requisition->budget_warning_acknowledged || (bool) ($snapshot['is_over_budget'] ?? false);
        $overageAmount = (string) ($snapshot['overage_amount'] ?? '0.00');

        foreach ($tokens as $bundle) {
            /** @var OneClickTokenBundle $bundle */
            $approver = User::query()->find($bundle->approverUserId);
            if ($approver === null) {
                continue;
            }

            $locale = $this->resolveLocale($approver, (string) $requisition->tenant_id);

            if ($approver->email !== null && $approver->email !== '') {
                Mail::to($approver->email)->queue(new RequisitionApprovalRequestedMail(
                    requisitionId: (string) $requisition->id,
                    requisitionNumber: (string) $requisition->requisition_number,
                    requesterName: $requesterName,
                    approverName: (string) $approver->name,
                    totalAmount: (string) $requisition->total_estimated_amount,
                    currency: (string) $requisition->currency,
                    costCenterLabel: $costCenterLabel,
                    requestedDeliveryDate: $deliveryDate,
                    urgency: (string) $requisition->urgency,
                    stepLevel: (int) $step->tier_level,
                    approveToken: $bundle->approveToken,
                    rejectToken: $bundle->rejectToken,
                    approveUrl: $bundle->approveUrl,
                    rejectUrl: $bundle->rejectUrl,
                    expiresAtIso: $bundle->expiresAtIso,
                    budgetWarning: $budgetWarning,
                    overageAmount: $overageAmount,
                    mailLocale: $locale,
                ));
            }

            $pushTitle = __('purchase_requisition.push.approval_requested_title', [
                'number' => $requisition->requisition_number,
                'level' => $step->tier_level,
            ], $locale);
            $pushBody = __('purchase_requisition.push.approval_requested_body', [
                'requester' => $requesterName,
                'amount' => $requisition->total_estimated_amount,
                'currency' => $requisition->currency,
                'urgency' => $requisition->urgency,
            ], $locale);

            $this->mobilePush->sendToUser(
                userId: (string) $approver->id,
                title: $pushTitle,
                body: $pushBody,
                data: [
                    'type' => 'purchase_requisition_approval_required',
                    'requisition_id' => (string) $requisition->id,
                    'requisition_number' => (string) $requisition->requisition_number,
                    'step_level' => (int) $step->tier_level,
                    'locale' => $locale,
                    'approve_token' => $bundle->pushApproveToken !== '' ? $bundle->pushApproveToken : $bundle->approveToken,
                    'reject_token' => $bundle->pushRejectToken !== '' ? $bundle->pushRejectToken : $bundle->rejectToken,
                    'actions' => [
                        ['id' => 'approve', 'title' => __('purchase_requisition.push.action_approve', [], $locale)],
                        ['id' => 'reject', 'title' => __('purchase_requisition.push.action_reject', [], $locale)],
                    ],
                ],
            );
        }
    }

    public function notifyRequesterBudgetBlocked(
        PurchaseRequisition $requisition,
        BudgetCheckResult $budgetCheck,
    ): void {
        $requester = User::query()->find($requisition->effectiveRequesterId());
        if ($requester === null) {
            return;
        }

        $locale = $this->resolveLocale($requester, (string) $requisition->tenant_id);
        $costCenterLabel = $this->resolveBudgetCostCenterLabel($requisition, $budgetCheck);

        if ($requester->email !== null && $requester->email !== '') {
            Mail::to($requester->email)->queue(new RequisitionBudgetBlockedMail(
                requisitionId: (string) $requisition->id,
                requisitionNumber: (string) $requisition->requisition_number,
                requesterName: (string) $requester->name,
                requestedAmount: $budgetCheck->requestedAmount,
                residualAmount: $budgetCheck->residualAvailable,
                overageAmount: $budgetCheck->overageAmount,
                currency: $budgetCheck->currency,
                costCenterLabel: $costCenterLabel,
                mailLocale: $locale,
            ));
        }

        $this->mobilePush->sendToUser(
            userId: (string) $requester->id,
            title: __('purchase_requisition.push.budget_blocked_title', [
                'number' => $requisition->requisition_number,
            ], $locale),
            body: __('purchase_requisition.push.budget_blocked_body', [
                'requested' => $budgetCheck->requestedAmount,
                'residual' => $budgetCheck->residualAvailable,
                'currency' => $budgetCheck->currency,
                'cost_center' => $costCenterLabel,
            ], $locale),
            data: [
                'type' => 'purchase_requisition_budget_blocked',
                'requisition_id' => (string) $requisition->id,
                'requisition_number' => (string) $requisition->requisition_number,
                'locale' => $locale,
            ],
        );
    }

    public function notifyRequesterBudgetWarning(
        PurchaseRequisition $requisition,
        BudgetCheckResult $budgetCheck,
    ): void {
        $requester = User::query()->find($requisition->effectiveRequesterId());
        if ($requester === null) {
            return;
        }

        $locale = $this->resolveLocale($requester, (string) $requisition->tenant_id);
        $costCenterLabel = $this->resolveBudgetCostCenterLabel($requisition, $budgetCheck);

        if ($requester->email !== null && $requester->email !== '') {
            Mail::to($requester->email)->queue(new RequisitionBudgetWarningMail(
                requisitionId: (string) $requisition->id,
                requisitionNumber: (string) $requisition->requisition_number,
                requesterName: (string) $requester->name,
                requestedAmount: $budgetCheck->requestedAmount,
                residualAmount: $budgetCheck->residualAvailable,
                overageAmount: $budgetCheck->overageAmount,
                currency: $budgetCheck->currency,
                costCenterLabel: $costCenterLabel,
                mailLocale: $locale,
            ));
        }

        $this->mobilePush->sendToUser(
            userId: (string) $requester->id,
            title: __('purchase_requisition.push.budget_warning_title', [
                'number' => $requisition->requisition_number,
            ], $locale),
            body: __('purchase_requisition.push.budget_warning_body', [
                'overage' => $budgetCheck->overageAmount,
                'currency' => $budgetCheck->currency,
                'cost_center' => $costCenterLabel,
            ], $locale),
            data: [
                'type' => 'purchase_requisition_budget_warning',
                'requisition_id' => (string) $requisition->id,
                'requisition_number' => (string) $requisition->requisition_number,
                'locale' => $locale,
            ],
        );
    }

    public function notifyRequesterApproved(
        PurchaseRequisition $requisition,
        User $approver,
        ?string $comment = null,
    ): void {
        $requester = User::query()->find($requisition->effectiveRequesterId());
        if ($requester === null) {
            return;
        }

        $locale = $this->resolveLocale($requester, (string) $requisition->tenant_id);

        if ($requester->email !== null && $requester->email !== '') {
            Mail::to($requester->email)->queue(new RequisitionApprovedMail(
                requisitionId: (string) $requisition->id,
                requisitionNumber: (string) $requisition->requisition_number,
                requesterName: (string) $requester->name,
                totalAmount: (string) $requisition->total_estimated_amount,
                currency: (string) $requisition->currency,
                comment: $comment,
                mailLocale: $locale,
            ));
        }

        $this->mobilePush->sendToUser(
            userId: (string) $requester->id,
            title: __('purchase_requisition.push.approved_title', [
                'number' => $requisition->requisition_number,
            ], $locale),
            body: __('purchase_requisition.push.approved_body', [
                'amount' => $requisition->total_estimated_amount,
                'currency' => $requisition->currency,
            ], $locale),
            data: [
                'type' => 'purchase_requisition_approved',
                'requisition_id' => (string) $requisition->id,
                'requisition_number' => (string) $requisition->requisition_number,
                'approved_by' => (string) $approver->id,
                'locale' => $locale,
            ],
        );
    }

    public function notifyRequesterRejected(
        PurchaseRequisition $requisition,
        User $approver,
        int $stepLevel,
        string $reason,
    ): void {
        $requester = User::query()->find($requisition->effectiveRequesterId());
        if ($requester === null) {
            return;
        }

        $locale = $this->resolveLocale($requester, (string) $requisition->tenant_id);

        if ($requester->email !== null && $requester->email !== '') {
            Mail::to($requester->email)->queue(new RequisitionRejectedMail(
                requisitionId: (string) $requisition->id,
                requisitionNumber: (string) $requisition->requisition_number,
                requesterName: (string) $requester->name,
                totalAmount: (string) $requisition->total_estimated_amount,
                currency: (string) $requisition->currency,
                stepLevel: $stepLevel,
                reason: $reason,
                mailLocale: $locale,
            ));
        }

        $this->mobilePush->sendToUser(
            userId: (string) $requester->id,
            title: __('purchase_requisition.push.rejected_title', [
                'number' => $requisition->requisition_number,
            ], $locale),
            body: __('purchase_requisition.push.rejected_body', [
                'level' => $stepLevel,
                'reason' => $reason,
            ], $locale),
            data: [
                'type' => 'purchase_requisition_rejected',
                'requisition_id' => (string) $requisition->id,
                'requisition_number' => (string) $requisition->requisition_number,
                'rejected_by' => (string) $approver->id,
                'step_level' => $stepLevel,
                'locale' => $locale,
            ],
        );
    }

    private function resolveLocale(User $user, string $tenantId): string
    {
        $userLocale = strtolower(substr(trim((string) ($user->locale ?? '')), 0, 2));
        if (in_array($userLocale, self::SupportedLocales, true)) {
            return $userLocale;
        }

        $country = strtoupper((string) (Tenant::query()->whereKey($tenantId)->value('country') ?? ''));

        return match ($country) {
            'IT' => 'it',
            'DE', 'AT' => 'de',
            'FR' => 'fr',
            'CH' => 'de',
            default => 'en',
        };
    }

    private function resolveCostCenterLabel(PurchaseRequisition $requisition): string
    {
        if ($requisition->cost_center_id === null || $requisition->cost_center_id === '') {
            return '-';
        }

        foreach (['cost_centers', 'analytical_cost_centers', 'hr_cost_centers'] as $table) {
            $row = DB::table($table)
                ->where('tenant_id', $requisition->tenant_id)
                ->where('id', $requisition->cost_center_id)
                ->first(['code', 'name']);

            if ($row !== null) {
                return trim("{$row->code} - {$row->name}", ' -');
            }
        }

        return (string) $requisition->cost_center_id;
    }

    private function resolveBudgetCostCenterLabel(PurchaseRequisition $requisition, BudgetCheckResult $budgetCheck): string
    {
        foreach ($budgetCheck->costCenters as $cc) {
            if ($cc->isOverBudget) {
                return $cc->costCenterCode !== null
                    ? trim("{$cc->costCenterCode} - {$cc->costCenterName}", ' -')
                    : $cc->costCenterId;
            }
        }

        return $this->resolveCostCenterLabel($requisition);
    }
}
