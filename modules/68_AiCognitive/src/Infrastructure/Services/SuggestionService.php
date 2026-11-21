<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveNotFoundException;
use Modules\AiCognitive\Domain\Exceptions\AiPolicyViolationException;
use Modules\AiCognitive\Domain\ValueObjects\SuggestionProposal;
use Modules\AiCognitive\Infrastructure\Executors\SuggestionExecutorInterface;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;

/**
 * Life cycle of the review queue. A proposal is stored with its confidence and evidence; when the tenant policy allows it
 * (confidence and amount) it is executed at once (zero-click), otherwise it waits in the Cognitive Review Workbench. Every
 * step is written to the audit trail, including the fields a reviewer changed.
 */
final readonly class SuggestionService
{
    /**
     * @param  iterable<SuggestionExecutorInterface>  $executors
     */
    public function __construct(
        private iterable $executors,
        private AutomationPolicy $policy,
        private AiAuditor $auditor,
    ) {}

    public function propose(SuggestionProposal $proposal): AiSuggestion
    {
        // The same source (email, bank transaction, subject of an alert) yields one open suggestion, however often it is analysed.
        $fingerprint = hash('sha256', $proposal->kind->value.'|'.($proposal->sourceReference ?? hash('sha256', (string) $proposal->sourceText.json_encode($proposal->payload))));

        $existing = AiSuggestion::query()
            ->where('tenant_id', $proposal->tenantId)
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', array_map(static fn (SuggestionStatus $status): string => $status->value, SuggestionStatus::open()))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $suggestion = AiSuggestion::query()->create([
            'tenant_id' => $proposal->tenantId,
            'kind' => $proposal->kind,
            'workflow' => $proposal->kind->workflow(),
            'source_type' => $proposal->sourceType,
            'source_reference' => $proposal->sourceReference,
            'source_text' => $proposal->sourceText,
            'proposed_payload_json' => $proposal->payload,
            'field_confidences_json' => $proposal->fieldConfidences,
            'confidence' => round($proposal->confidence, 2),
            'amount' => $proposal->amount,
            'reasoning' => $proposal->reasoning,
            'citations_json' => $proposal->citations,
            'status' => SuggestionStatus::Pending,
            'priority' => $proposal->priority,
            'fingerprint' => $fingerprint,
            'provider_used' => $proposal->provider,
            'model_used' => $proposal->model,
            'expires_at' => now()->addDays((int) config('ai_cognitive.automation.suggestion_ttl_days', 30)),
        ]);

        $this->auditor->record($proposal->tenantId, 'suggestion_created', $proposal->userId, 'ai_suggestion', $suggestion->id, $proposal->provider, $proposal->model, $proposal->confidence, [
            'kind' => $proposal->kind->value,
            'field_confidences' => $proposal->fieldConfidences,
            'citations' => $proposal->citations,
            'amount' => $proposal->amount,
        ]);

        if ($this->policy->allowsAutoApproval($proposal->tenantId, $proposal->kind, $proposal->confidence, $proposal->amount)) {
            try {
                return $this->decide($suggestion, null, SuggestionStatus::AutoApproved, $proposal->payload, null);
            } catch (AiCognitiveException $exception) {
                // An incomplete proposal is not an error of the pipeline: a person completes it in the workbench.
                $suggestion->update(['reasoning' => trim(($suggestion->reasoning ?? '').' Auto approval not possible: '.$exception->getMessage())]);
                $this->auditor->record($proposal->tenantId, 'auto_approval_failed', null, 'ai_suggestion', $suggestion->id, $proposal->provider, $proposal->model, $proposal->confidence, ['reason' => $exception->getMessage()]);
            }
        }

        return $suggestion;
    }

    /**
     * @param  array<string, mixed>|null  $editedPayload  the payload as the reviewer corrected it; null approves as proposed
     *
     * @throws AiCognitiveException
     */
    public function approve(string $tenantId, string $id, ?string $userId, ?array $editedPayload = null, ?string $feedback = null): AiSuggestion
    {
        $suggestion = $this->open($tenantId, $id);
        $final = $editedPayload ?? $suggestion->proposed_payload_json;
        $edited = $editedPayload !== null && $editedPayload != $suggestion->proposed_payload_json;

        return $this->decide($suggestion, $userId, $edited ? SuggestionStatus::EditedApproved : SuggestionStatus::Approved, $final, $feedback);
    }

    /**
     * @throws AiCognitiveException
     */
    public function reject(string $tenantId, string $id, ?string $userId, string $reason): AiSuggestion
    {
        $suggestion = $this->open($tenantId, $id);

        $suggestion->update([
            'status' => SuggestionStatus::Rejected,
            'decided_by' => $userId,
            'decided_at' => now(),
            'decision_feedback' => $reason,
        ]);

        $this->auditor->record($tenantId, 'suggestion_rejected', $userId, 'ai_suggestion', $suggestion->id, $suggestion->provider_used, $suggestion->model_used, (float) $suggestion->confidence, [
            'kind' => $suggestion->kind->value,
            'reason_length' => mb_strlen($reason),
        ]);

        return $suggestion->refresh();
    }

    public function expireStale(): int
    {
        return AiSuggestion::query()
            ->where('status', SuggestionStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => SuggestionStatus::Expired->value]);
    }

    /**
     * @throws AiCognitiveNotFoundException
     * @throws AiPolicyViolationException
     */
    private function open(string $tenantId, string $id): AiSuggestion
    {
        $suggestion = AiSuggestion::query()->where('tenant_id', $tenantId)->find($id);

        if (! $suggestion instanceof AiSuggestion) {
            throw AiCognitiveNotFoundException::suggestion($id);
        }

        if (! $suggestion->status->isOpen()) {
            throw AiPolicyViolationException::suggestionClosed($suggestion->status->value);
        }

        return $suggestion;
    }

    /**
     * @param  array<string, mixed>  $final
     */
    private function decide(AiSuggestion $suggestion, ?string $userId, SuggestionStatus $status, array $final, ?string $feedback): AiSuggestion
    {
        $outcome = DB::transaction(function () use ($suggestion, $userId, $status, $final, $feedback): array {
            $outcome = $this->executorFor($suggestion->kind)->execute($suggestion->tenant_id, $userId, $suggestion->kind, $final);

            $suggestion->update([
                'status' => $status,
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_feedback' => $feedback,
                'final_payload_json' => $final,
                'resulting_entity_type' => $outcome['type'],
                'resulting_entity_id' => $outcome['id'],
            ]);

            return $outcome;
        });

        $this->auditor->record($suggestion->tenant_id, 'suggestion_'.$status->value, $userId, 'ai_suggestion', $suggestion->id, $suggestion->provider_used, $suggestion->model_used, (float) $suggestion->confidence, [
            'kind' => $suggestion->kind->value,
            'edited_fields' => $this->changedFields($suggestion->proposed_payload_json, $final),
            'resulting_entity_type' => $outcome['type'],
            'resulting_entity_id' => $outcome['id'],
        ]);

        return $suggestion->refresh();
    }

    private function executorFor(SuggestionKind $kind): SuggestionExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($kind)) {
                return $executor;
            }
        }

        throw new AiCognitiveException("No executor handles [{$kind->value}].");
    }

    /**
     * Top level keys whose value differs: enough for the audit trail to show what a human corrected without copying values.
     *
     * @param  array<string, mixed>  $proposed
     * @param  array<string, mixed>  $final
     * @return list<string>
     */
    private function changedFields(array $proposed, array $final): array
    {
        $changed = [];

        foreach (array_unique([...array_keys($proposed), ...array_keys($final)]) as $key) {
            if (($proposed[$key] ?? null) != ($final[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }
}
