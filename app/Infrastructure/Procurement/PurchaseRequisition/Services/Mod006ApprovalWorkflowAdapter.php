<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Services\DelegationThresholdDomainService;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\ApprovalTierSpec;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mobile\RoleResolver;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseRequisition as LegacyPurchaseRequisition;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\WorkflowAssigneeResolver;
use Modules\Workflow\Services\WorkflowAuditLedgerService;

/**
 * Mod 006 Workflow & Delegation Matrix adapter for Mod 044 Purchase Requisitions,
 * including multi-level threshold routing, four-eyes enforcement, and single-use HMAC-signed 1-click tokens.
 */
final readonly class Mod006ApprovalWorkflowAdapter implements ApprovalWorkflowGatewayInterface
{
    private const string WorkflowCode = 'MOD044_PR_APPROVAL';

    public function __construct(
        private ApprovalMatrixEvaluatorInterface $matrixEvaluator,
        private DelegationThresholdDomainService $thresholdService,
        private WorkflowAssigneeResolver $assigneeResolver,
        private WorkflowAuditLedgerService $auditLedger,
        private RoleResolver $roleResolver,
        private TenantContext $tenantContext,
    ) {}

    public function resolveApprovalChain(PurchaseRequisition $requisition): array
    {
        $tenantId = (string) $requisition->tenant_id;
        $currency = strtoupper((string) ($requisition->currency !== '' ? $requisition->currency : 'EUR'));
        $amount = (string) $requisition->total_estimated_amount;
        $context = [
            'amount' => $amount,
            'cost_center_id' => $requisition->cost_center_id,
            'currency' => $currency,
        ];

        $matrixTiers = $this->matrixEvaluator->tiersFor($tenantId, PurchaseRequisition::class, $requisition, $context);

        if ($matrixTiers === null) {
            $matrixTiers = $this->matrixEvaluator->tiersFor($tenantId, LegacyPurchaseRequisition::class, $requisition, $context);
        }

        if ($matrixTiers !== null) {
            $specs = [];
            foreach ($matrixTiers as $tier) {
                $specs[] = new ApprovalTierSpec(
                    level: (int) $tier['tier_level'],
                    approverType: (string) $tier['approver_type'],
                    approverValue: (string) $tier['approver_value'],
                    label: ucfirst(str_replace('_', ' ', (string) $tier['approver_value'])),
                    thresholdMin: null,
                    thresholdMax: null,
                    currency: $currency,
                );
            }

            usort($specs, static fn (ApprovalTierSpec $a, ApprovalTierSpec $b): int => $a->level <=> $b->level);

            return $specs;
        }

        $tiers = $this->thresholdService->resolveMatchingTiers($amount, $currency);

        return array_map(function (ApprovalTierSpec $tier) use ($requisition, $tenantId): ApprovalTierSpec {
            if ($tier->level === 1 && $tier->approverType === 'role' && $tier->approverValue === 'department_manager') {
                $requesterId = $requisition->effectiveRequesterId();
                $managerId = $requesterId !== '' ? $this->assigneeResolver->managerUserIdOf($requesterId, $tenantId) : null;

                if ($managerId !== null && $managerId !== $requesterId && $this->assigneeResolver->isMember($managerId, $tenantId)) {
                    return new ApprovalTierSpec(
                        level: $tier->level,
                        approverType: 'user',
                        approverValue: $managerId,
                        label: $tier->label,
                        thresholdMin: $tier->thresholdMin,
                        thresholdMax: $tier->thresholdMax,
                        currency: $tier->currency,
                    );
                }
            }

            return $tier;
        }, $tiers);
    }

    public function startApprovalWorkflow(
        PurchaseRequisition $requisition,
        array $tiers,
        string $submitterUserId,
    ): array {
        $tenantId = (string) $requisition->tenant_id;
        [$definition, $approvalNode] = $this->ensureWorkflowDefinition($tenantId);

        $makers = array_values(array_unique(array_filter([
            $submitterUserId,
            $requisition->effectiveRequesterId(),
            (string) ($requisition->created_by ?? ''),
        ])));

        $instance = WorkflowInstance::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $requisition->company_id,
            'workflow_definition_id' => $definition->id,
            'workflow_definition_version' => $definition->version,
            'workflow_definition_snapshot' => [
                'id' => $definition->id,
                'code' => $definition->code,
                'version' => $definition->version,
            ],
            'approvable_type' => PurchaseRequisition::class,
            'approvable_id' => (string) $requisition->id,
            'current_node_id' => $approvalNode->id,
            'status' => 'in_progress',
            'context_snapshot' => [
                'amount' => (string) $requisition->total_estimated_amount,
                'currency' => (string) $requisition->currency,
                'cost_center_id' => $requisition->cost_center_id,
                'four_eyes' => true,
                'maker_user_ids' => $makers,
                'approval_chain' => array_map(static fn (ApprovalTierSpec $tier): array => $tier->toArray(), $tiers),
            ],
            'started_at' => CarbonImmutable::now('UTC'),
            'created_by' => $submitterUserId,
        ]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'instance_started',
            payload: [
                'definition_code' => $definition->code,
                'tiers_count' => count($tiers),
            ],
            actorUserId: $submitterUserId,
        );

        $firstTier = $tiers[0];
        $step = $this->createStepForTier($instance, $approvalNode, $firstTier);
        $tokens = $this->generateTokensForStep($requisition, $step, $makers);

        return [
            'instance' => $instance,
            'active_step' => $step,
            'tokens' => $tokens,
        ];
    }

    public function validateAndDecideInApp(
        PurchaseRequisition $requisition,
        User $actor,
        string $action,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $tenantId = (string) $requisition->tenant_id;
        [$instance, $step] = $this->lockActiveInstanceAndStep($requisition);

        $this->assertFourEyesAndStepAuthority($requisition, $instance, $step, $actor, $tenantId);

        return $this->commitStepDecision(
            requisition: $requisition,
            instance: $instance,
            step: $step,
            actor: $actor,
            action: $action,
            comment: $comment,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            approvalTokenId: null,
        );
    }

    public function validateAndDecideByToken(
        string $rawToken,
        string $action,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $payload = $this->verifySignedTokenPayload($rawToken);
        $normalizedAction = strtolower(trim($action));

        if (! in_array($normalizedAction, ['approve', 'reject'], true)) {
            throw new ProcurementRuleViolation(__('purchase_requisition.validation.action_invalid'), 'action');
        }

        if (strtolower((string) ($payload['act'] ?? '')) !== $normalizedAction) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_action_mismatch'), 'action');
        }

        if (CarbonImmutable::now('UTC')->timestamp > (int) ($payload['exp'] ?? 0)) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_expired'), 'token');
        }

        $tokenHash = hash('sha256', trim($rawToken));

        /** @var ApprovalToken|null $tokenLookup */
        $tokenLookup = ApprovalToken::withoutGlobalScopes()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($tokenLookup === null) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        if ((string) $tokenLookup->tenant_id !== (string) ($payload['tid'] ?? '')) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        $tenant = Tenant::query()->find($tokenLookup->tenant_id);
        if ($tenant === null) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        return $this->tenantContext->runWithTenant($tenant, function () use (
            $tokenLookup,
            $payload,
            $normalizedAction,
            $comment,
            $ipAddress,
            $userAgent,
            $tenant,
        ): array {
            return DB::transaction(function () use (
                $tokenLookup,
                $payload,
                $normalizedAction,
                $comment,
                $ipAddress,
                $userAgent,
                $tenant,
            ): array {
                /** @var ApprovalToken|null $lockedToken */
                $lockedToken = ApprovalToken::query()
                    ->whereKey($tokenLookup->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedToken === null) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
                }

                if ($lockedToken->used_at !== null) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_already_used'), 'token');
                }

                if (CarbonImmutable::now('UTC')->isAfter($lockedToken->expires_at)) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_expired'), 'token');
                }

                if (strtolower((string) $lockedToken->action_type) !== $normalizedAction) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_action_mismatch'), 'action');
                }

                /** @var WorkflowInstanceStep|null $step */
                $step = WorkflowInstanceStep::query()
                    ->whereKey($lockedToken->workflow_instance_step_id)
                    ->lockForUpdate()
                    ->first();

                if ($step === null || $step->status !== 'pending') {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_already_used'), 'token');
                }

                /** @var WorkflowInstance $instance */
                $instance = WorkflowInstance::query()
                    ->whereKey($step->workflow_instance_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($instance->status, ['pending', 'in_progress'], true)) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_already_used'), 'token');
                }

                /** @var PurchaseRequisition|null $requisition */
                $requisition = PurchaseRequisition::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey((string) $payload['rid'])
                    ->lockForUpdate()
                    ->first();

                if ($requisition === null || (string) $instance->approvable_id !== (string) $requisition->id) {
                    throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
                }

                if ($requisition->statusEnum() !== RequisitionStatus::PendingApproval) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.errors.not_pending_approval', ['status' => $requisition->status]),
                        'status'
                    );
                }

                /** @var User $actor */
                $actor = User::query()->findOrFail($lockedToken->authorized_user_id);
                $this->assertFourEyesAndStepAuthority($requisition, $instance, $step, $actor, (string) $tenant->id);

                $lockedToken->update(['used_at' => CarbonImmutable::now('UTC')]);

                $result = $this->commitStepDecision(
                    requisition: $requisition,
                    instance: $instance,
                    step: $step,
                    actor: $actor,
                    action: $normalizedAction,
                    comment: $comment,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                    approvalTokenId: (string) $lockedToken->id,
                );

                $channel = ApprovalChannel::tryFrom((string) ($payload['ch'] ?? ''))
                    ?? ApprovalChannel::EmailOneClick;

                return [
                    'requisition' => $requisition,
                    'actor_user_id' => (string) $actor->id,
                    'channel' => $channel,
                    ...$result,
                ];
            });
        });
    }

    public function getApprovalSteps(PurchaseRequisition $requisition): array
    {
        if ($requisition->workflow_instance_id === null) {
            return [];
        }

        /** @var WorkflowInstance|null $instance */
        $instance = WorkflowInstance::query()->find($requisition->workflow_instance_id);

        if ($instance === null) {
            return [];
        }

        $chain = is_array($instance->context_snapshot['approval_chain'] ?? null)
            ? $instance->context_snapshot['approval_chain']
            : [];

        $steps = WorkflowInstanceStep::query()
            ->where('workflow_instance_id', $instance->id)
            ->orderBy('tier_level')
            ->get();

        $decisionsByStepId = ApprovalDecision::query()
            ->whereIn('workflow_instance_step_id', $steps->pluck('id'))
            ->get()
            ->keyBy('workflow_instance_step_id');

        $deciderIds = $decisionsByStepId->pluck('user_id')->filter()->unique()->values()->all();
        $deciderNames = $deciderIds !== []
            ? User::query()->whereIn('id', $deciderIds)->pluck('name', 'id')
            : collect();

        $resolveDeciderName = static fn (?ApprovalDecision $decision): ?string => $decision !== null && $decision->user_id !== null
            ? $deciderNames->get((string) $decision->user_id)
            : null;

        $stepsByLevel = $steps->keyBy('tier_level');
        $result = [];

        foreach ($chain as $tier) {
            $level = (int) ($tier['level'] ?? 1);
            $step = $stepsByLevel->get($level);
            $decision = $step !== null ? $decisionsByStepId->get((string) $step->id) : null;

            $result[] = [
                'level' => $level,
                'label' => (string) ($tier['label'] ?? 'Level '.$level),
                'approver_type' => (string) ($tier['approver_type'] ?? 'role'),
                'approver_value' => (string) ($tier['approver_value'] ?? ''),
                'status' => $step !== null ? (string) $step->status : 'upcoming',
                'step_id' => $step !== null ? (string) $step->id : null,
                'assigned_user_id' => $step?->assigned_user_id,
                'assigned_role' => $step?->assigned_role,
                'due_at' => $step?->due_at?->toIso8601String(),
                'completed_at' => $step?->completed_at?->toIso8601String(),
                'decided_by_user_id' => $decision?->user_id,
                'decided_by_name' => $resolveDeciderName($decision),
                'comment' => $decision?->comment,
            ];
        }

        if ($result === []) {
            foreach ($steps as $step) {
                $decision = $decisionsByStepId->get((string) $step->id);
                $result[] = [
                    'level' => (int) $step->tier_level,
                    'label' => 'Level '.$step->tier_level,
                    'approver_type' => $step->assigned_user_id !== null ? 'user' : 'role',
                    'approver_value' => (string) ($step->assigned_user_id ?? $step->assigned_role ?? ''),
                    'status' => (string) $step->status,
                    'step_id' => (string) $step->id,
                    'assigned_user_id' => $step->assigned_user_id,
                    'assigned_role' => $step->assigned_role,
                    'due_at' => $step->due_at?->toIso8601String(),
                    'completed_at' => $step->completed_at?->toIso8601String(),
                    'decided_by_user_id' => $decision?->user_id,
                    'decided_by_name' => $resolveDeciderName($decision),
                    'comment' => $decision?->comment,
                ];
            }
        }

        return $result;
    }

    public function getPendingRequisitionIdsForUser(string $tenantId, User $user): array
    {
        $roles = $this->roleResolver->roles($user, $tenantId)->all();
        $expandedRoles = $roles;
        if (array_intersect($roles, ['ceo', 'owner']) !== []) {
            $expandedRoles[] = 'cfo';
        }
        $expandedRoles = array_values(array_unique($expandedRoles));

        $delegatorIds = WorkflowDelegation::query()
            ->where('tenant_id', $tenantId)
            ->where('delegate_user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get()
            ->filter(static fn (WorkflowDelegation $d): bool => $d->coversApprovable(PurchaseRequisition::class) || $d->coversApprovable(LegacyPurchaseRequisition::class))
            ->pluck('delegator_user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $steps = WorkflowInstanceStep::query()
            ->with('instance')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->where(static function ($query) use ($user, $expandedRoles, $delegatorIds): void {
                $query->where('assigned_user_id', $user->id);

                if ($delegatorIds !== []) {
                    $query->orWhereIn('assigned_user_id', $delegatorIds);
                }

                if ($expandedRoles !== []) {
                    $query->orWhereIn('assigned_role', $expandedRoles);
                }
            })
            ->whereHas('instance', static function ($query): void {
                $query->whereIn('status', ['pending', 'in_progress'])
                    ->whereIn('approvable_type', [PurchaseRequisition::class, LegacyPurchaseRequisition::class]);
            })
            ->get();

        $ids = [];
        foreach ($steps as $step) {
            $instance = $step->instance;
            if ($instance === null) {
                continue;
            }

            $makers = array_filter([
                (string) $instance->created_by,
                ...(array) ($instance->context_snapshot['maker_user_ids'] ?? []),
            ]);

            if (in_array((string) $user->id, $makers, true)) {
                continue;
            }

            $alreadyDecided = ApprovalDecision::query()
                ->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
                ->where(static fn ($q) => $q->where('user_id', $user->id)->orWhere('effective_user_id', $user->id))
                ->exists();

            if ($alreadyDecided) {
                continue;
            }

            $ids[] = (string) $instance->approvable_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolves concrete user accounts eligible to receive notifications & 1-click tokens for a step.
     *
     * @param  list<string>  $excludedUserIds
     * @return list<User>
     */
    private function resolveStepRecipients(string $tenantId, WorkflowInstanceStep $step, array $excludedUserIds): array
    {
        $excluded = array_flip($excludedUserIds);

        if ($step->assigned_user_id !== null && $step->assigned_user_id !== '') {
            if (isset($excluded[(string) $step->assigned_user_id])) {
                return [];
            }

            $user = User::query()->whereKey($step->assigned_user_id)->first();

            return $user !== null ? [$user] : [];
        }

        $role = strtolower((string) ($step->assigned_role ?? ''));
        if ($role === '') {
            return [];
        }

        $candidateRoles = $role === 'cfo' ? ['cfo', 'ceo', 'owner'] : [$role];

        $userIds = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->whereNull('suspended_at')
            ->whereIn(DB::raw('LOWER(role)'), $candidateRoles)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        // When exact cfo/ceo role members exist, prefer them over generic owner fallback
        if ($role === 'cfo') {
            $exactCfoIds = DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->whereNull('suspended_at')
                ->whereIn(DB::raw('LOWER(role)'), ['cfo', 'ceo'])
                ->pluck('user_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            if ($exactCfoIds !== []) {
                $userIds = $exactCfoIds;
            }
        }

        $filteredIds = array_values(array_filter(
            array_unique($userIds),
            static fn (string $id): bool => ! isset($excluded[$id])
        ));

        if ($filteredIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $filteredIds)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /**
     * @param  list<string>  $excludedUserIds
     * @return list<OneClickTokenBundle>
     */
    private function generateTokensForStep(
        PurchaseRequisition $requisition,
        WorkflowInstanceStep $step,
        array $excludedUserIds,
    ): array {
        $tenantId = (string) $requisition->tenant_id;
        $recipients = $this->resolveStepRecipients($tenantId, $step, $excludedUserIds);
        $ttlHours = max(1, (int) config('procurement_requisitions.one_click_token_ttl_hours', 48));
        $expiresAt = CarbonImmutable::now('UTC')->addHours($ttlHours);
        $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', 'https://localhost')), '/');
        $frontendPath = '/'.ltrim((string) config('procurement_requisitions.frontend_one_click_path', '/procurement/purchase-requisitions/one-click-action'), '/');

        $bundles = [];
        foreach ($recipients as $recipient) {
            $emailApproveToken = $this->issueSignedToken($tenantId, $requisition, $step, $recipient, 'approve', ApprovalChannel::EmailOneClick, $expiresAt);
            $emailRejectToken = $this->issueSignedToken($tenantId, $requisition, $step, $recipient, 'reject', ApprovalChannel::EmailOneClick, $expiresAt);
            $pushApproveToken = $this->issueSignedToken($tenantId, $requisition, $step, $recipient, 'approve', ApprovalChannel::PushOneClick, $expiresAt);
            $pushRejectToken = $this->issueSignedToken($tenantId, $requisition, $step, $recipient, 'reject', ApprovalChannel::PushOneClick, $expiresAt);

            $bundles[] = new OneClickTokenBundle(
                approverUserId: (string) $recipient->id,
                approveToken: $emailApproveToken,
                rejectToken: $emailRejectToken,
                approveUrl: $frontendBase.$frontendPath.'?action=approve&token='.urlencode($emailApproveToken),
                rejectUrl: $frontendBase.$frontendPath.'?action=reject&token='.urlencode($emailRejectToken),
                expiresAtIso: $expiresAt->toIso8601String(),
                pushApproveToken: $pushApproveToken,
                pushRejectToken: $pushRejectToken,
            );
        }

        return $bundles;
    }

    private function issueSignedToken(
        string $tenantId,
        PurchaseRequisition $requisition,
        WorkflowInstanceStep $step,
        User $recipient,
        string $action,
        ApprovalChannel $channel,
        CarbonImmutable $expiresAt,
    ): string {
        $payload = [
            'v' => 1,
            'jti' => bin2hex(random_bytes(16)),
            'tid' => $tenantId,
            'rid' => (string) $requisition->id,
            'sid' => (string) $step->id,
            'uid' => (string) $recipient->id,
            'act' => $action,
            'ch' => $channel->value,
            'exp' => $expiresAt->timestamp,
        ];

        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->signingKey());
        $signedToken = $encoded.'.'.$signature;

        ApprovalToken::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_instance_step_id' => $step->id,
            'token_hash' => hash('sha256', $signedToken),
            'action_type' => $action,
            'authorized_user_id' => $recipient->id,
            'expires_at' => $expiresAt,
            'used_at' => null,
        ]);

        return $signedToken;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProcurementRuleViolation
     */
    private function verifySignedTokenPayload(string $rawToken): array
    {
        $trimmed = trim($rawToken);
        $parts = explode('.', $trimmed);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        [$encodedPayload, $providedSignature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->signingKey());

        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        $decodedJson = $this->base64UrlDecode($encodedPayload);
        if ($decodedJson === null) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        $data = json_decode($decodedJson, true);
        if (! is_array($data) || ! isset($data['rid'], $data['sid'], $data['uid'], $data['act'], $data['exp'])) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.token_invalid'), 'token');
        }

        return $data;
    }

    private function signingKey(): string
    {
        return (string) config('app.key', 'mod044-requisition-signing-key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @return array{0: WorkflowInstance, 1: WorkflowInstanceStep}
     *
     * @throws ProcurementRuleViolation
     */
    private function lockActiveInstanceAndStep(PurchaseRequisition $requisition): array
    {
        /** @var WorkflowInstance|null $instance */
        $instance = $requisition->workflow_instance_id !== null
            ? WorkflowInstance::query()->whereKey($requisition->workflow_instance_id)->lockForUpdate()->first()
            : WorkflowInstance::query()
                ->where('tenant_id', $requisition->tenant_id)
                ->whereIn('approvable_type', [PurchaseRequisition::class, LegacyPurchaseRequisition::class])
                ->where('approvable_id', (string) $requisition->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->first();

        if ($instance === null) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.no_pending_step'), 'status');
        }

        /** @var WorkflowInstanceStep|null $step */
        $step = WorkflowInstanceStep::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('status', 'pending')
            ->orderBy('tier_level')
            ->lockForUpdate()
            ->first();

        if ($step === null) {
            throw new ProcurementRuleViolation(__('purchase_requisition.errors.no_pending_step'), 'status');
        }

        return [$instance, $step];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertFourEyesAndStepAuthority(
        PurchaseRequisition $requisition,
        WorkflowInstance $instance,
        WorkflowInstanceStep $step,
        User $actor,
        string $tenantId,
    ): void {
        $makers = array_filter([
            (string) $instance->created_by,
            $requisition->effectiveRequesterId(),
            (string) ($requisition->created_by ?? ''),
            ...(array) ($instance->context_snapshot['maker_user_ids'] ?? []),
        ]);

        if (in_array((string) $actor->id, $makers, true)) {
            throw new AuthorizationException(__('purchase_requisition.errors.four_eyes_violation'));
        }

        $alreadyApproved = ApprovalDecision::query()
            ->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
            ->where('decision', 'approved')
            ->where(static fn ($q) => $q->where('user_id', $actor->id)->orWhere('effective_user_id', $actor->id))
            ->exists();

        if ($alreadyApproved) {
            throw new AuthorizationException(__('purchase_requisition.errors.already_approved_earlier_level'));
        }

        if ($step->assigned_user_id !== null && $step->assigned_user_id !== '') {
            if ((string) $step->assigned_user_id === (string) $actor->id) {
                return;
            }

            $delegation = WorkflowDelegation::query()
                ->where('tenant_id', $tenantId)
                ->where('delegator_user_id', $step->assigned_user_id)
                ->where('delegate_user_id', $actor->id)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->get()
                ->first(static fn (WorkflowDelegation $candidate): bool => $candidate->coversApprovable(PurchaseRequisition::class)
                    || $candidate->coversApprovable(LegacyPurchaseRequisition::class));

            if ($delegation !== null) {
                return;
            }
        }

        if ($step->assigned_role !== null && $step->assigned_role !== '') {
            $requiredRole = strtolower((string) $step->assigned_role);
            $actorRoles = $this->roleResolver->roles($actor, $tenantId)->all();

            if (in_array($requiredRole, $actorRoles, true)) {
                return;
            }

            if ($requiredRole === 'cfo' && array_intersect($actorRoles, ['ceo', 'owner']) !== []) {
                return;
            }

            if ($requiredRole === 'admin' && in_array('owner', $actorRoles, true)) {
                return;
            }
        }

        throw new AuthorizationException(__('purchase_requisition.errors.approver_not_authorized', [
            'level' => $step->tier_level,
        ]));
    }

    /**
     * @return array{step: WorkflowInstanceStep, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string}
     */
    private function commitStepDecision(
        PurchaseRequisition $requisition,
        WorkflowInstance $instance,
        WorkflowInstanceStep $step,
        User $actor,
        string $action,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $approvalTokenId,
    ): array {
        $decision = $action === 'approve' ? 'approved' : 'rejected';
        $now = CarbonImmutable::now('UTC');

        ApprovalDecision::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $instance->tenant_id,
            'workflow_instance_step_id' => $step->id,
            'user_id' => $actor->id,
            'effective_user_id' => $actor->id,
            'decision' => $decision,
            'comment' => $comment,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 500) : null,
            'approval_token_id' => $approvalTokenId,
            'decided_at' => $now,
        ]);

        $step->update([
            'status' => $decision,
            'completed_at' => $now,
        ]);

        // Invalidate all remaining unused 1-click tokens issued for this step (both email and push pairs)
        ApprovalToken::query()
            ->where('workflow_instance_step_id', $step->id)
            ->whereNull('used_at')
            ->update(['used_at' => $now]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'decision_recorded',
            payload: [
                'decision' => $decision,
                'step_id' => $step->id,
                'tier_level' => (int) $step->tier_level,
                'comment' => $comment,
            ],
            step: $step,
            actorUserId: (string) $actor->id,
        );

        if ($decision === 'rejected') {
            WorkflowInstanceStep::query()
                ->where('workflow_instance_id', $instance->id)
                ->where('status', 'pending')
                ->update(['status' => 'skipped', 'completed_at' => $now]);

            $instance->update([
                'status' => 'rejected',
                'completed_at' => $now,
            ]);

            return [
                'step' => $step,
                'next_step' => null,
                'next_tokens' => [],
                'completed' => true,
                'outcome' => 'rejected',
            ];
        }

        // Look up next sequential tier from snapshot
        $nextTier = $this->findNextTier($instance, (int) $step->tier_level);
        if ($nextTier !== null) {
            /** @var WorkflowNode $approvalNode */
            $approvalNode = WorkflowNode::query()->findOrFail($step->workflow_node_id);
            $nextStep = $this->createStepForTier($instance, $approvalNode, $nextTier);

            $excluded = array_values(array_unique(array_filter([
                ...(array) ($instance->context_snapshot['maker_user_ids'] ?? []),
                (string) $actor->id,
                ...ApprovalDecision::query()
                    ->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
                    ->pluck('user_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all(),
            ])));

            $nextTokens = $this->generateTokensForStep($requisition, $nextStep, $excluded);

            return [
                'step' => $step,
                'next_step' => $nextStep,
                'next_tokens' => $nextTokens,
                'completed' => false,
                'outcome' => 'pending',
            ];
        }

        $instance->update([
            'status' => 'approved',
            'completed_at' => $now,
        ]);

        return [
            'step' => $step,
            'next_step' => null,
            'next_tokens' => [],
            'completed' => true,
            'outcome' => 'approved',
        ];
    }

    private function findNextTier(WorkflowInstance $instance, int $currentLevel): ?ApprovalTierSpec
    {
        $chain = $instance->context_snapshot['approval_chain'] ?? [];
        if (! is_array($chain)) {
            return null;
        }

        $candidates = [];
        foreach ($chain as $item) {
            if (! is_array($item)) {
                continue;
            }
            $level = (int) ($item['level'] ?? 0);
            if ($level > $currentLevel) {
                $candidates[] = new ApprovalTierSpec(
                    level: $level,
                    approverType: (string) $item['approver_type'],
                    approverValue: (string) $item['approver_value'],
                    label: (string) ($item['label'] ?? 'Level '.$level),
                    thresholdMin: isset($item['threshold_min']) ? (string) $item['threshold_min'] : null,
                    thresholdMax: isset($item['threshold_max']) ? (string) $item['threshold_max'] : null,
                    currency: (string) ($item['currency'] ?? 'EUR'),
                );
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (ApprovalTierSpec $a, ApprovalTierSpec $b): int => $a->level <=> $b->level);

        return $candidates[0];
    }

    private function createStepForTier(
        WorkflowInstance $instance,
        WorkflowNode $node,
        ApprovalTierSpec $tier,
    ): WorkflowInstanceStep {
        $dueAt = CarbonImmutable::now('UTC')->addHours($node->timeout_hours ?? 48);

        $step = WorkflowInstanceStep::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $instance->tenant_id,
            'workflow_instance_id' => $instance->id,
            'workflow_node_id' => $node->id,
            'assigned_user_id' => $tier->approverType === 'user' ? $tier->approverValue : null,
            'assigned_role' => $tier->approverType === 'role' ? $tier->approverValue : null,
            'status' => 'pending',
            'tier_level' => $tier->level,
            'due_at' => $dueAt,
        ]);

        $this->auditLedger->record(
            instance: $instance,
            eventType: 'step_assigned',
            payload: [
                'step_id' => $step->id,
                'tier_level' => $tier->level,
                'assigned_user_id' => $step->assigned_user_id,
                'assigned_role' => $step->assigned_role,
            ],
            step: $step,
        );

        return $step;
    }

    /**
     * @return array{0: WorkflowDefinition, 1: WorkflowNode}
     */
    private function ensureWorkflowDefinition(string $tenantId): array
    {
        $definition = WorkflowDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', self::WorkflowCode)
            ->where('is_active', true)
            ->first();

        if ($definition !== null) {
            $approvalNode = WorkflowNode::query()
                ->where('workflow_definition_id', $definition->id)
                ->where('node_type', 'approval')
                ->first();
            if ($approvalNode !== null) {
                return [$definition, $approvalNode];
            }
        }

        $definition = WorkflowDefinition::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => self::WorkflowCode,
            'name' => 'Purchase Requisition Multi-Level Approval (Mod 044)',
            'target_entity_type' => PurchaseRequisition::class,
            'version' => 1,
            'status' => 'published',
            'is_active' => true,
            'published_at' => now(),
        ]);

        $start = WorkflowNode::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => 'start',
            'name' => 'Start',
            'node_type' => 'start',
        ]);

        $approval = WorkflowNode::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => 'approval',
            'name' => 'Approval',
            'node_type' => 'approval',
            'approval_type' => 'and_parallel',
            'assignee_type' => 'matrix_sequential',
            'timeout_hours' => 720,
        ]);

        $end = WorkflowNode::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => 'end',
            'name' => 'End',
            'node_type' => 'end',
        ]);

        foreach ([[$start, $approval, 'start'], [$approval, $end, 'approved']] as [$from, $to, $trigger]) {
            WorkflowTransition::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'workflow_definition_id' => $definition->id,
                'from_node_id' => $from->id,
                'to_node_id' => $to->id,
                'action_trigger' => $trigger,
            ]);
        }

        return [$definition, $approval];
    }
}
