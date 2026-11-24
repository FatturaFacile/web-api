<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Plm\Models\PlmItemRevision;

/**
 * Revisions of manufacturing items. The first revision of an item is released directly; once an item has a released
 * revision, every further release goes through an implemented change order.
 */
final class ItemRevisionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): PlmItemRevision
    {
        $this->assertRevisionFree((string) $data['mfg_item_id'], (string) $data['revision'], null);

        return PlmItemRevision::create([...$data, 'tenant_id' => $tenantId, 'status' => PlmItemRevision::Draft])->refresh();
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

            $revision->update(['status' => PlmItemRevision::Released, 'released_at' => now(), 'effective_from' => $revision->effective_from ?? now()->toDateString()]);

            return $revision->refresh();
        });
    }

    public function obsolete(PlmItemRevision $revision): PlmItemRevision
    {
        if ($revision->status !== PlmItemRevision::Released) {
            throw ValidationException::withMessages(['revision' => [__('plm_errors.revision.not_released')]]);
        }

        $revision->update(['status' => PlmItemRevision::Obsolete, 'obsoleted_at' => now()]);

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
