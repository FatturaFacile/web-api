<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Models\LossReason;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Support\SalesPipelineCatalog;

/**
 * Configuration of the sales pipelines of a tenant (per business line / market) and of the loss-reason catalog.
 * Every tenant always has exactly one default pipeline; stages and deals that predate pipelines are adopted by it.
 */
final readonly class SalesPipelineService
{
    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * The default pipeline of the tenant, created on first use; stages and opportunities without a pipeline join it.
     */
    public function ensureDefaultPipeline(string $tenantId): Pipeline
    {
        return DB::transaction(function () use ($tenantId): Pipeline {
            $default = Pipeline::where('tenant_id', $tenantId)->where('is_default', true)->first();

            if ($default === null) {
                $default = Pipeline::where('tenant_id', $tenantId)->orderBy('sequence')->first();
                if ($default !== null) {
                    $default->update(['is_default' => true, 'is_active' => true]);
                } else {
                    $definition = SalesPipelineCatalog::pipelines()[0];
                    $default = Pipeline::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'code' => $definition['code'],
                        'name' => $definition['name'],
                        'business_line' => $definition['business_line'],
                        'market' => $definition['market'],
                        'translations' => $definition['translations'],
                        'sequence' => $definition['sequence'],
                        'is_default' => true,
                        'is_active' => true,
                    ]);
                }
            }

            OpportunityStage::where('tenant_id', $tenantId)->whereNull('pipeline_id')->update(['pipeline_id' => $default->id]);
            Opportunity::where('tenant_id', $tenantId)->whereNull('pipeline_id')->update(['pipeline_id' => $default->id]);

            return $default;
        });
    }

    /**
     * Installs the standard pipelines, stages and loss reasons the tenant lacks, matched by code. Safe to re-run:
     * existing rows keep their configuration and only gain missing labels.
     *
     * @return array{pipelines: int, stages: int, loss_reasons: int}
     */
    public function installCatalog(string $tenantId): array
    {
        return DB::transaction(function () use ($tenantId): array {
            $default = $this->ensureDefaultPipeline($tenantId);
            $created = ['pipelines' => 0, 'stages' => 0, 'loss_reasons' => 0];

            foreach (SalesPipelineCatalog::pipelines() as $definition) {
                $pipeline = $definition['is_default']
                    ? $default
                    : Pipeline::withTrashed()->where('tenant_id', $tenantId)->where('code', $definition['code'])->first();

                if ($pipeline === null) {
                    $pipeline = Pipeline::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'code' => $definition['code'],
                        'name' => $definition['name'],
                        'business_line' => $definition['business_line'],
                        'market' => $definition['market'],
                        'translations' => $definition['translations'],
                        'sequence' => $definition['sequence'],
                        'is_default' => false,
                        'is_active' => true,
                    ]);
                    $created['pipelines']++;
                } elseif ($pipeline->translations === null) {
                    $pipeline->update(['translations' => $definition['translations']]);
                }

                $created['stages'] += $this->installStages($tenantId, $pipeline, $definition['stage_prefix']);
            }

            foreach (SalesPipelineCatalog::lossReasons() as $reason) {
                $existing = LossReason::withTrashed()->where('tenant_id', $tenantId)->where('code', $reason['code'])->first();
                if ($existing === null) {
                    LossReason::create([...$reason, 'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'is_active' => true]);
                    $created['loss_reasons']++;
                } elseif ($existing->translations === null) {
                    $existing->update(['translations' => $reason['translations']]);
                }
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPipeline(string $tenantId, array $data): Pipeline
    {
        return DB::transaction(function () use ($tenantId, $data): Pipeline {
            $pipeline = Pipeline::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => $data['code'],
                'name' => $data['name'],
                'business_line' => $data['business_line'] ?? null,
                'market' => $data['market'] ?? null,
                'currency' => $data['currency'] ?? 'CHF',
                'translations' => $data['translations'] ?? null,
                'sequence' => $data['sequence'] ?? 10,
                'is_default' => false,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['install_standard_stages'])) {
                $this->installStages($tenantId, $pipeline, Str::substr((string) $data['code'], 0, 12).'_');
            }

            if (! empty($data['is_default'])) {
                $this->makeDefault($pipeline);
            }

            $this->auditLedger->record(action: 'CRM_PIPELINE_CREATED', model: $pipeline, payloadBefore: null, payloadAfter: $pipeline->toArray(), moduleCode: 'MODULE_02_CRM');

            return $pipeline->fresh() ?? $pipeline;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePipeline(Pipeline $pipeline, array $data): Pipeline
    {
        return DB::transaction(function () use ($pipeline, $data): Pipeline {
            $before = $pipeline->toArray();

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $pipeline->is_default) {
                throw ValidationException::withMessages(['is_active' => [__('crm_pipeline.pipeline_default_not_deletable')]]);
            }

            $pipeline->update(array_intersect_key($data, array_flip(['name', 'business_line', 'market', 'currency', 'translations', 'sequence', 'is_active'])));

            if (! empty($data['is_default']) && ! $pipeline->is_default) {
                $this->makeDefault($pipeline);
            }

            $this->auditLedger->record(action: 'CRM_PIPELINE_UPDATED', model: $pipeline, payloadBefore: $before, payloadAfter: $pipeline->toArray(), moduleCode: 'MODULE_02_CRM');

            return $pipeline->fresh() ?? $pipeline;
        });
    }

    /**
     * A pipeline in use can only be deactivated; the default one can never be removed.
     */
    public function deletePipeline(Pipeline $pipeline): void
    {
        if ($pipeline->is_default) {
            throw ValidationException::withMessages(['pipeline' => [__('crm_pipeline.pipeline_default_not_deletable')]]);
        }

        if (Opportunity::withTrashed()->where('pipeline_id', $pipeline->id)->exists()) {
            throw ValidationException::withMessages(['pipeline' => [__('crm_pipeline.pipeline_in_use')]]);
        }

        DB::transaction(function () use ($pipeline): void {
            $this->auditLedger->record(action: 'CRM_PIPELINE_DELETED', model: $pipeline, payloadBefore: $pipeline->toArray(), payloadAfter: null, moduleCode: 'MODULE_02_CRM');
            OpportunityStage::where('pipeline_id', $pipeline->id)->delete();
            $pipeline->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLossReason(string $tenantId, array $data): LossReason
    {
        $reason = LossReason::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'translations' => $data['translations'] ?? null,
            'requires_competitor' => $data['requires_competitor'] ?? false,
            'sequence' => $data['sequence'] ?? 10,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->auditLedger->record(action: 'CRM_LOSS_REASON_CREATED', model: $reason, payloadBefore: null, payloadAfter: $reason->toArray(), moduleCode: 'MODULE_02_CRM');

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLossReason(LossReason $reason, array $data): LossReason
    {
        $before = $reason->toArray();
        $reason->update(array_intersect_key($data, array_flip(['name', 'translations', 'requires_competitor', 'sequence', 'is_active'])));
        $this->auditLedger->record(action: 'CRM_LOSS_REASON_UPDATED', model: $reason, payloadBefore: $before, payloadAfter: $reason->toArray(), moduleCode: 'MODULE_02_CRM');

        return $reason;
    }

    public function deleteLossReason(LossReason $reason): void
    {
        if (Opportunity::withTrashed()->where('tenant_id', $reason->tenant_id)->where('loss_reason_code', $reason->code)->exists()) {
            throw ValidationException::withMessages(['loss_reason' => [__('crm_pipeline.loss_reason_in_use')]]);
        }

        $this->auditLedger->record(action: 'CRM_LOSS_REASON_DELETED', model: $reason, payloadBefore: $reason->toArray(), payloadAfter: null, moduleCode: 'MODULE_02_CRM');
        $reason->delete();
    }

    private function makeDefault(Pipeline $pipeline): void
    {
        Pipeline::where('tenant_id', $pipeline->tenant_id)->where('id', '!=', $pipeline->id)->update(['is_default' => false]);
        $pipeline->update(['is_default' => true, 'is_active' => true]);
    }

    private function installStages(string $tenantId, Pipeline $pipeline, string $prefix): int
    {
        $created = 0;

        foreach (SalesPipelineCatalog::stages() as $stage) {
            $code = $prefix.$stage['code'];
            $existing = OpportunityStage::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->first();

            if ($existing === null) {
                OpportunityStage::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'pipeline_id' => $pipeline->id,
                    'code' => $code,
                    'name' => $stage['name'],
                    'translations' => $stage['translations'],
                    'sequence' => $stage['sequence'],
                    'default_probability' => $stage['probability'],
                    'is_won' => $stage['is_won'],
                    'is_lost' => $stage['is_lost'],
                    'forecast_category' => $stage['forecast_category'],
                    'stale_days' => $stage['stale_days'],
                    'is_active' => true,
                ]);
                $created++;
            } else {
                $patch = [];
                if ($existing->translations === null) {
                    $patch['translations'] = $stage['translations'];
                }
                if ($existing->pipeline_id === null) {
                    $patch['pipeline_id'] = $pipeline->id;
                }
                if ($patch !== []) {
                    $existing->update($patch);
                }
            }
        }

        return $created;
    }
}
