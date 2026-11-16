<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgMaterialityAssessment;
use Modules\Sustainability\Models\EsgMaterialityTopic;
use Modules\Sustainability\Support\Decimal;

/**
 * Double materiality (ESRS 1): every topic is scored 0-5 on impact materiality and on financial materiality, and is
 * material when either score reaches the assessment threshold. A draft can be rescored freely; approval makes it final.
 */
final readonly class MaterialityService
{
    public function __construct(private SustainabilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): EsgMaterialityAssessment
    {
        return DB::transaction(function () use ($tenantId, $data): EsgMaterialityAssessment {
            $assessment = EsgMaterialityAssessment::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'reporting_period_id' => $data['reporting_period_id'] ?? null,
                'threshold' => $data['threshold'] ?? '3',
                'status' => EsgMaterialityAssessment::Draft,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncTopics($assessment, $data['topics'] ?? []);

            return $assessment->load('topics');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EsgMaterialityAssessment $assessment, array $data): EsgMaterialityAssessment
    {
        $this->assertDraft($assessment);

        return DB::transaction(function () use ($assessment, $data): EsgMaterialityAssessment {
            $assessment->update(array_intersect_key($data, array_flip(['name', 'reporting_period_id', 'threshold', 'notes'])));
            $topics = array_key_exists('topics', $data) ? $data['topics'] : $assessment->topics()->get()->map(static fn (EsgMaterialityTopic $topic): array => $topic->only(['esrs_code', 'name', 'impact_score', 'financial_score', 'rationale']))->all();
            $this->syncTopics($assessment, $topics);

            return $assessment->refresh()->load('topics');
        });
    }

    public function approve(EsgMaterialityAssessment $assessment, ?string $actorId): EsgMaterialityAssessment
    {
        return DB::transaction(function () use ($assessment, $actorId): EsgMaterialityAssessment {
            $locked = EsgMaterialityAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== EsgMaterialityAssessment::Draft) {
                throw ValidationException::withMessages(['status' => [__('sustainability_errors.materiality.status_expected', ['status' => $locked->status, 'expected' => EsgMaterialityAssessment::Draft])]]);
            }

            $topics = $locked->topics()->get();
            if ($topics->isEmpty()) {
                throw ValidationException::withMessages(['topics' => [__('sustainability_errors.materiality.no_topics')]]);
            }

            $locked->update(['status' => EsgMaterialityAssessment::Approved, 'approved_at' => now(), 'approved_by' => $actorId]);
            $this->ledger->record('ESG_MATERIALITY_APPROVED', $locked, ['topics' => $topics->count(), 'material_topics' => $topics->where('is_material', true)->count(), 'threshold' => (string) $locked->threshold]);

            return $locked->refresh()->load('topics');
        });
    }

    /**
     * Whether a topic is material: either score reaches the threshold.
     */
    public function isMaterial(string $impact, string $financial, string $threshold): bool
    {
        return Decimal::compare($impact, $threshold) >= 0 || Decimal::compare($financial, $threshold) >= 0;
    }

    private function assertDraft(EsgMaterialityAssessment $assessment): void
    {
        if ($assessment->status !== EsgMaterialityAssessment::Draft) {
            throw ValidationException::withMessages(['status' => [__('sustainability_errors.materiality.locked')]]);
        }
    }

    /**
     * Replaces the topics of the assessment and recomputes their materiality against the current threshold.
     *
     * @param  list<array<string, mixed>>  $topics
     */
    private function syncTopics(EsgMaterialityAssessment $assessment, array $topics): void
    {
        EsgMaterialityTopic::query()->where('tenant_id', $assessment->tenant_id)->where('assessment_id', $assessment->id)->forceDelete();

        foreach ($topics as $topic) {
            EsgMaterialityTopic::create([
                'tenant_id' => $assessment->tenant_id,
                'assessment_id' => $assessment->id,
                'esrs_code' => $topic['esrs_code'],
                'name' => $topic['name'],
                'impact_score' => Decimal::round((string) $topic['impact_score'], 2),
                'financial_score' => Decimal::round((string) $topic['financial_score'], 2),
                'is_material' => $this->isMaterial((string) $topic['impact_score'], (string) $topic['financial_score'], (string) $assessment->threshold),
                'rationale' => $topic['rationale'] ?? null,
            ]);
        }
    }
}
