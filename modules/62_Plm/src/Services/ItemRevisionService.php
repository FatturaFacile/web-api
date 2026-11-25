<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Models\PlmItemRevision;

/**
 * Revisions of manufacturing items. Enforces strict lifecycle state machine transitions:
 * InDesign_Prototype -> UnderReview -> ReleasedForProduction -> PhaseOut_UseUpStock -> ObsoleteBlocked.
 */
final class ItemRevisionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): PlmItemRevision
    {
        $this->assertRevisionFree((string) $data['mfg_item_id'], (string) $data['revision'], null);

        $lifecycle = RevisionLifecycleState::tryFrom((string) ($data['lifecycle_state'] ?? RevisionLifecycleState::InDesignPrototype->value))
            ?? RevisionLifecycleState::InDesignPrototype;

        if ($lifecycle === RevisionLifecycleState::ReleasedForProduction || $lifecycle === RevisionLifecycleState::ObsoleteBlocked) {
            throw ValidationException::withMessages(['lifecycle_state' => [__('plm_errors.revision.invalid_initial_state')]]);
        }

        return PlmItemRevision::create([
            ...$data,
            'tenant_id' => $tenantId,
            'status' => $lifecycle->legacyStatus(),
            'lifecycle_state' => $lifecycle->value,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlmItemRevision $revision, array $data): PlmItemRevision
    {
        $this->assertDraft($revision);

        if (isset($data['revision'])) {
            $this->assertRevisionFree((string) $revision->mfg_item_id, (string) $data['revision'], (string) $revision->id);
        }

        if (isset($data['lifecycle_state'])) {
            $target = RevisionLifecycleState::tryFrom((string) $data['lifecycle_state']);
            if ($target === null || ! $revision->lifecycleStateEnum()->canTransitionTo($target)) {
                throw ValidationException::withMessages(['lifecycle_state' => [__('plm_errors.revision.invalid_transition')]]);
            }
            $data['status'] = $target->legacyStatus();
        }

        $revision->update($data);

        return $revision->refresh();
    }

    public function delete(PlmItemRevision $revision): void
    {
        $this->assertDraft($revision);
        $revision->delete();
    }

    public function release(PlmItemRevision $revision): PlmItemRevision
    {
        return DB::transaction(function () use ($revision): PlmItemRevision {
            $this->assertDraft($revision);

            if (PlmItemRevision::query()->where('mfg_item_id', $revision->mfg_item_id)->where('status', PlmItemRevision::Released)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['revision' => [__('plm_errors.revision.change_order_required')]]);
            }

            $revision->update([
                'status' => PlmItemRevision::Released,
                'lifecycle_state' => RevisionLifecycleState::ReleasedForProduction->value,
                'released_at' => now(),
                'effective_from' => $revision->effective_from ?? now()->toDateString(),
                'mes_drawing_obsolete_watermark' => false,
            ]);

            return $revision->refresh();
        });
    }

    public function obsolete(PlmItemRevision $revision): PlmItemRevision
    {
        if ($revision->status !== PlmItemRevision::Released) {
            throw ValidationException::withMessages(['revision' => [__('plm_errors.revision.not_released')]]);
        }

        $revision->update([
            'status' => PlmItemRevision::Obsolete,
            'lifecycle_state' => RevisionLifecycleState::ObsoleteBlocked->value,
            'obsoleted_at' => now(),
            'mes_drawing_obsolete_watermark' => true,
        ]);

        return $revision->refresh();
    }

    private function assertDraft(PlmItemRevision $revision): void
    {
        if ($revision->status !== PlmItemRevision::Draft) {
            throw ValidationException::withMessages(['revision' => [__('plm_errors.revision.not_draft')]]);
        }
    }

    private function assertRevisionFree(string $itemId, string $label, ?string $ignoreId): void
    {
        $taken = PlmItemRevision::query()
            ->where('mfg_item_id', $itemId)
            ->where('revision', $label)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['revision' => [__('plm_errors.revision.exists', ['revision' => $label])]]);
        }
    }
}
