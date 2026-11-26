<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FiduciaryPortal\Models\FiduciaryAccountTranscoding;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class FiduciaryTranscodingService
{
    public function __construct(private FiduciaryAuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function matrix(string $tenantId, string $studioSystem = 'universal', ?string $search = null, bool $unmappedOnly = false): array
    {
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $transcodings = FiduciaryAccountTranscoding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('studio_system', $studioSystem)
            ->where('is_active', true)
            ->get()
            ->keyBy(static fn (FiduciaryAccountTranscoding $t): string => (string) $t->source_account_id);

        $rows = [];
        $unmappedCount = 0;

        foreach ($accounts as $account) {
            $rule = $transcodings->get((string) $account->id);
            $isMapped = $rule !== null && trim((string) $rule->studio_account_code) !== '';

            if ($account->is_postable && ! $isMapped) {
                $unmappedCount++;
            }

            if ($unmappedOnly && $isMapped) {
                continue;
            }

            if ($search !== null && trim($search) !== '') {
                $needle = mb_strtolower(trim($search));
                $haystack = mb_strtolower(implode(' ', [
                    (string) $account->account_code,
                    (string) $account->name,
                    (string) ($rule?->studio_account_code ?? ''),
                    (string) ($rule?->studio_account_name ?? ''),
                ]));
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $rows[] = [
                'transcoding_id' => $rule?->id !== null ? (string) $rule->id : null,
                'source_account_id' => (string) $account->id,
                'source_account_code' => (string) $account->account_code,
                'source_account_name' => (string) $account->name,
                'source_standard' => (string) ($account->standard ?? 'it_oic'),
                'account_type' => (string) $account->account_type,
                'is_postable' => (bool) $account->is_postable,
                'studio_system' => $studioSystem,
                'studio_account_code' => $rule?->studio_account_code,
                'studio_account_name' => $rule?->studio_account_name,
                'studio_vat_code' => $rule?->studio_vat_code,
                'studio_cost_center_code' => $rule?->studio_cost_center_code,
                'notes' => $rule?->notes,
                'is_mapped' => $isMapped,
            ];
        }

        return [
            'studio_system' => $studioSystem,
            'total_accounts' => $accounts->count(),
            'mapped_accounts' => $transcodings->count(),
            'unmapped_postable_accounts' => $unmappedCount,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRule(string $tenantId, array $data, ?User $actor = null): FiduciaryAccountTranscoding
    {
        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail((string) $data['source_account_id']);

        $studioSystem = (string) ($data['studio_system'] ?? 'universal');

        $rule = FiduciaryAccountTranscoding::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'studio_system' => $studioSystem,
                'source_account_id' => (string) $account->id,
            ],
            [
                'id' => (string) Str::uuid7(),
                'practitioner_user_id' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                'source_account_code' => (string) $account->account_code,
                'source_account_name' => (string) $account->name,
                'source_standard' => (string) ($account->standard ?? 'it_oic'),
                'studio_account_code' => trim((string) $data['studio_account_code']),
                'studio_account_name' => trim((string) ($data['studio_account_name'] ?? $account->name)),
                'studio_vat_code' => isset($data['studio_vat_code']) ? trim((string) $data['studio_vat_code']) : null,
                'studio_cost_center_code' => isset($data['studio_cost_center_code']) ? trim((string) $data['studio_cost_center_code']) : null,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
                'updated_by' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
            ]
        );

        $this->audit->record(
            tenantId: $tenantId,
            action: 'transcoding_saved',
            summary: "Mapped account {$account->account_code} to studio code {$rule->studio_account_code} ({$studioSystem}).",
            actor: $actor,
            targetType: 'fiduciary_account_transcoding',
            targetId: (string) $rule->id,
            targetReference: "{$account->account_code} -> {$rule->studio_account_code}",
            metadata: ['studio_system' => $studioSystem, 'source_account_code' => $account->account_code, 'studio_account_code' => $rule->studio_account_code],
            auditModel: $rule,
        );

        return $rule;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{saved: int, rules: list<FiduciaryAccountTranscoding>}
     */
    public function bulkUpsert(string $tenantId, string $studioSystem, array $items, ?User $actor = null): array
    {
        $saved = [];

        DB::transaction(function () use ($tenantId, $studioSystem, $items, $actor, &$saved): void {
            foreach ($items as $item) {
                $item['studio_system'] = $studioSystem;
                $saved[] = $this->saveRule($tenantId, $item, $actor);
            }
        });

        $this->audit->record(
            tenantId: $tenantId,
            action: 'transcoding_bulk_imported',
            summary: 'Bulk updated '.count($saved)." transcoding rules for studio system [{$studioSystem}].",
            actor: $actor,
            targetType: 'fiduciary_account_transcoding',
            metadata: ['studio_system' => $studioSystem, 'count' => count($saved)],
        );

        return ['saved' => count($saved), 'rules' => $saved];
    }

    /**
     * Seeds default studio mappings based on account codes and types (KMU / OIC).
     */
    public function seedDefaults(string $tenantId, string $studioSystem = 'universal', ?User $actor = null): int
    {
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->get();

        $created = 0;

        DB::transaction(function () use ($tenantId, $studioSystem, $accounts, $actor, &$created): void {
            foreach ($accounts as $account) {
                $exists = FiduciaryAccountTranscoding::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('studio_system', $studioSystem)
                    ->where('source_account_id', $account->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $digits = preg_replace('/\D+/', '', (string) $account->account_code) ?: '9999';
                $studioCode = match ($studioSystem) {
                    'datev_koinos' => str_pad(mb_substr($digits, 0, 4), 4, '0', STR_PAD_RIGHT),
                    'abacus_xml', 'abacus_ascii', 'topal', 'sage50_extra', 'swiss_fiduciary_universal' => str_pad(mb_substr($digits, 0, 4), 4, '0', STR_PAD_RIGHT),
                    default => (string) $account->account_code,
                };

                FiduciaryAccountTranscoding::withoutGlobalScopes()->create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'practitioner_user_id' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                    'studio_system' => $studioSystem,
                    'source_account_id' => (string) $account->id,
                    'source_account_code' => (string) $account->account_code,
                    'source_account_name' => (string) $account->name,
                    'source_standard' => (string) ($account->standard ?? 'it_oic'),
                    'studio_account_code' => $studioCode,
                    'studio_account_name' => (string) $account->name,
                    'studio_vat_code' => null,
                    'is_active' => true,
                    'created_by' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                ]);
                $created++;
            }
        });

        return $created;
    }

    public function deleteRule(string $tenantId, string $id, ?User $actor = null): void
    {
        $rule = FiduciaryAccountTranscoding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $ref = "{$rule->source_account_code} -> {$rule->studio_account_code}";
        $rule->delete();

        $this->audit->record(
            tenantId: $tenantId,
            action: 'transcoding_deleted',
            summary: "Deleted transcoding rule {$ref}.",
            actor: $actor,
            targetType: 'fiduciary_account_transcoding',
            targetId: $id,
            targetReference: $ref,
        );
    }

    /**
     * @return array<string, FiduciaryAccountTranscoding> keyed by source_account_id
     */
    public function mapForTenant(string $tenantId, string $studioSystem = 'universal'): array
    {
        $rules = FiduciaryAccountTranscoding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('studio_system', $studioSystem)
            ->where('is_active', true)
            ->get();

        if ($rules->isEmpty() && $studioSystem !== 'universal') {
            $rules = FiduciaryAccountTranscoding::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('studio_system', 'universal')
                ->where('is_active', true)
                ->get();
        }

        return $rules->keyBy(static fn (FiduciaryAccountTranscoding $r): string => (string) $r->source_account_id)->all();
    }
}
