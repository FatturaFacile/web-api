<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Services;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryHandler;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Jobs\ProcessAuditLogJob;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class AuditLedgerService implements AuditLedgerInterface
{
    public function __construct(
        private readonly RecordAuditAction $recordAction,
        private readonly AppendAuditEntryHandler $appendHandler,
    ) {}

    public function record(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?string $moduleCode = null
    ): void {
        if (config('erp_foundation.audit.async', false)) {
            ProcessAuditLogJob::dispatch($this->payloadFor($action, $model, $payloadBefore, $payloadAfter, $moduleCode));

            return;
        }

        try {
            $this->recordAction->execute($action, $model, $payloadBefore, $payloadAfter, $moduleCode);
        } catch (Throwable $e) {
            $this->failClosedUnlessReadOnly($action, $e, fn (): array => $this->payloadFor($action, $model, $payloadBefore, $payloadAfter, $moduleCode));
        }
    }

    public function recordPayload(array $data): void
    {
        if (config('erp_foundation.audit.async', false)) {
            ProcessAuditLogJob::dispatch($data);

            return;
        }

        try {
            $this->recordAction->executeFromPayload($data);
        } catch (Throwable $e) {
            $this->failClosedUnlessReadOnly((string) ($data['action'] ?? ''), $e, static fn (): array => $data);
        }
    }

    public function append(AppendAuditEntryCommand $command): void
    {
        try {
            $this->appendHandler->handle($command);
        } catch (Throwable $e) {
            if (! $command->action->isReadOnly()) {
                throw $e;
            }

            report($e);
            ProcessAuditLogJob::dispatch($this->payloadOf($command));
        }
    }

    /**
     * Mutations and business transitions fail closed: the exception reaches the caller, whose transaction rolls back.
     * A read-only event cannot change a record, so it is queued and retried instead of blocking the request.
     *
     * @param  callable(): array<string, mixed>  $payload
     */
    private function failClosedUnlessReadOnly(string $action, Throwable $e, callable $payload): void
    {
        if ($action === '' || ! AuditAction::make($action)->isReadOnly()) {
            throw $e;
        }

        report($e);
        ProcessAuditLogJob::dispatch($payload());
    }

    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     * @return array<string, mixed>
     */
    private function payloadFor(string $action, Model $model, ?array $payloadBefore, ?array $payloadAfter, ?string $moduleCode): array
    {
        $tenantId = (string) ($model->getAttribute('tenant_id') ?? '00000000-0000-0000-0000-000000000000');

        return [
            'action' => $action,
            'model_class' => $model::class,
            'model_id' => (string) $model->getKey(),
            'tenant_id' => $tenantId,
            'company_id' => (string) ($model->getAttribute('company_id') ?? $tenantId),
            'payload_before' => $payloadBefore,
            'payload_after' => $payloadAfter,
            'module_code' => $moduleCode,
            'actor_user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'personal_attributes' => method_exists($model, 'auditPersonalAttributes') ? $model->auditPersonalAttributes() : [],
            'excluded_attributes' => method_exists($model, 'auditExcludedAttributes') ? $model->auditExcludedAttributes() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(AppendAuditEntryCommand $command): array
    {
        return [
            'tenant_id' => $command->tenantId,
            'company_id' => $command->companyId,
            'module_code' => $command->moduleCode,
            'action' => $command->action->value,
            'model_class' => $command->resourceType,
            'model_id' => $command->resourceId,
            'payload_before' => $command->before,
            'payload_after' => $command->after,
            'actor_user_id' => $command->actorUserId ?? auth()->id(),
            'actor_type' => $command->actorType,
            'category' => $command->category?->value,
            'personal_attributes' => $command->personalAttributes,
            'excluded_attributes' => $command->excludedAttributes,
            'context' => $command->context,
            'channel' => $command->client?->channel,
            'ip_address' => $command->client?->ipAddress,
            'user_agent' => $command->client?->userAgent,
        ];
    }
}
