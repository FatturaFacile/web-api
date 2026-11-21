<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Infrastructure\Models\AiAuditTrail;

/**
 * Appends to the hash chained `ai_audit_trails`: inferences, suggestions, confidence scores, source citations and every
 * human accept, edit or reject. The chain is per tenant; verify() recomputes it. Prompts and answers never enter the trail,
 * only their fingerprints and sizes, so the trail itself holds no personal data.
 */
final class AiAuditor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $tenantId, string $event, ?string $actorUserId = null, ?string $subjectType = null, ?string $subjectId = null, ?string $provider = null, ?string $model = null, ?float $confidence = null, array $payload = []): AiAuditTrail
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): AiAuditTrail => $this->append($tenantId, $event, $actorUserId, $subjectType, $subjectId, $provider, $model, $confidence, $payload));
            } catch (UniqueConstraintViolationException $exception) {
                // Two writers took the same sequence: the loser takes the next one.
                if ($attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return array{valid: bool, checked: int, broken_at: int|null}
     */
    public function verify(string $tenantId): array
    {
        $previous = null;
        $checked = 0;
        $expected = 1;

        foreach (AiAuditTrail::query()->where('tenant_id', $tenantId)->orderBy('sequence')->cursor() as $row) {
            $recomputed = $this->hash($row->tenant_id, $row->sequence, $row->event_type, $row->actor_user_id, $row->subject_type, $row->subject_id, $row->provider, $row->model, $row->confidence, $row->payload_json ?? [], $previous, (string) $row->created_at?->toIso8601String());

            if ($row->sequence !== $expected || $row->previous_hash !== $previous || $row->hash !== $recomputed) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $row->sequence];
            }

            $previous = $row->hash;
            $expected++;
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function append(string $tenantId, string $event, ?string $actorUserId, ?string $subjectType, ?string $subjectId, ?string $provider, ?string $model, ?float $confidence, array $payload): AiAuditTrail
    {
        $last = AiAuditTrail::query()->where('tenant_id', $tenantId)->orderByDesc('sequence')->first();
        $sequence = ($last->sequence ?? 0) + 1;
        $createdAt = now();
        $confidence = $confidence === null ? null : round($confidence, 2);

        return AiAuditTrail::query()->create([
            'tenant_id' => $tenantId,
            'sequence' => $sequence,
            'event_type' => $event,
            'actor_user_id' => $actorUserId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'provider' => $provider,
            'model' => $model,
            'confidence' => $confidence,
            'payload_json' => $payload,
            'previous_hash' => $last?->hash,
            'hash' => $this->hash($tenantId, $sequence, $event, $actorUserId, $subjectType, $subjectId, $provider, $model, $confidence === null ? null : number_format($confidence, 2, '.', ''), $payload, $last?->hash, $createdAt->toIso8601String()),
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(string $tenantId, int $sequence, string $event, ?string $actor, ?string $subjectType, ?string $subjectId, ?string $provider, ?string $model, mixed $confidence, array $payload, ?string $previous, string $createdAt): string
    {
        $confidence = $confidence === null ? null : number_format((float) $confidence, 2, '.', '');

        return hash('sha256', (string) json_encode([$tenantId, $sequence, $event, $actor, $subjectType, $subjectId, $provider, $model, $confidence, $payload, $previous, $createdAt], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
