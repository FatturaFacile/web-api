<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use DomainException;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

/**
 * Tenant-configurable sales pipeline. Each stage carries the win probability used for weighted pipeline and forecast;
 * changing it can reprice the open opportunities of the stage so the weighted figures follow the new policy.
 */
final readonly class PipelineStageService
{
    /**
     * The stages installed for a tenant that has none, matching the CRM labels shipped in every language.
     */
    public const array DefaultStages = [
        ['code' => 'discovery', 'name' => 'Discovery', 'sequence' => 10, 'default_probability' => 10, 'is_won' => false, 'is_lost' => false],
        ['code' => 'qualification', 'name' => 'Qualification', 'sequence' => 20, 'default_probability' => 25, 'is_won' => false, 'is_lost' => false],
        ['code' => 'proposal', 'name' => 'Proposal', 'sequence' => 30, 'default_probability' => 50, 'is_won' => false, 'is_lost' => false],
        ['code' => 'negotiation', 'name' => 'Negotiation', 'sequence' => 40, 'default_probability' => 75, 'is_won' => false, 'is_lost' => false],
        ['code' => 'closed_won', 'name' => 'Closed Won', 'sequence' => 90, 'default_probability' => 100, 'is_won' => true, 'is_lost' => false],
        ['code' => 'closed_lost', 'name' => 'Closed Lost', 'sequence' => 100, 'default_probability' => 0, 'is_won' => false, 'is_lost' => true],
    ];

    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * Installs the default stages that the tenant does not have yet (matched by code); existing stages are untouched.
     *
     * @return int number of stages created
     */
    public function installDefaults(string $tenantId): int
    {
        $created = 0;

        foreach (self::DefaultStages as $stage) {
            $exists = OpportunityStage::withTrashed()->where('tenant_id', $tenantId)->where('code', $stage['code'])->exists();
            if (! $exists) {
                OpportunityStage::create([...$stage, 'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'is_active' => true, 'stale_days' => 30]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): OpportunityStage
    {
        $this->assertNotWonAndLost($data);

        $stage = OpportunityStage::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? 10,
            'default_probability' => $data['default_probability'],
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
            'stale_days' => $data['stale_days'] ?? 30,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLedger->record(action: 'CRM_PIPELINE_STAGE_CREATED', model: $stage, payloadBefore: null, payloadAfter: $stage->toArray(), moduleCode: 'MODULE_02_CRM');

        return $stage;
    }

    /**
     * Updates a stage; with `reprice_open_opportunities` a new probability is applied to the open opportunities of the
     * stage (probability and expected revenue).
     *
     * @param  array<string, mixed>  $data
     * @return array{stage: OpportunityStage, repriced: int}
     */
    public function update(OpportunityStage $stage, array $data): array
    {
        $this->assertNotWonAndLost([...$stage->only(['is_won', 'is_lost']), ...$data]);

        return DB::transaction(function () use ($stage, $data): array {
            $before = $stage->toArray();
            $stage->update(array_intersect_key($data, array_flip(['name', 'sequence', 'default_probability', 'is_won', 'is_lost', 'stale_days', 'is_active'])));

            $repriced = 0;
            if (! empty($data['reprice_open_opportunities']) && array_key_exists('default_probability', $data)) {
                $probability = (float) $stage->default_probability;
                Opportunity::where('tenant_id', $stage->tenant_id)
                    ->where('stage_id', $stage->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Opportunity $opportunity) use ($probability, &$repriced): void {
                        $opportunity->update([
                            'probability' => $probability,
                            'expected_revenue' => round(((float) $opportunity->amount * $probability) / 100.0, 2),
                        ]);
                        $repriced++;
                    });
            }

            $this->auditLedger->record(action: 'CRM_PIPELINE_STAGE_UPDATED', model: $stage, payloadBefore: $before, payloadAfter: [...$stage->toArray(), 'repriced_opportunities' => $repriced], moduleCode: 'MODULE_02_CRM');

            return ['stage' => $stage, 'repriced' => $repriced];
        });
    }

    /**
     * Removes a stage that no opportunity uses; a used stage can only be deactivated.
     *
     * @throws DomainException
     */
    public function delete(OpportunityStage $stage): void
    {
        if (Opportunity::withTrashed()->where('stage_id', $stage->id)->exists()) {
            throw new DomainException('The stage is used by opportunities: deactivate it instead.');
        }

        $this->auditLedger->record(action: 'CRM_PIPELINE_STAGE_DELETED', model: $stage, payloadBefore: $stage->toArray(), payloadAfter: null, moduleCode: 'MODULE_02_CRM');
        $stage->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNotWonAndLost(array $data): void
    {
        if (! empty($data['is_won']) && ! empty($data['is_lost'])) {
            throw new DomainException('A stage cannot be both won and lost.');
        }
    }
}
