<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\PpeAction;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;

/**
 * Garments with a certified wash limit (PPE, EN ISO 20471 / 11612 / 13034 or the manufacturer limit): the monitor of the ones
 * that are close to it or past it, and the decision on each: replace it, send it to mending or condemn it as a rag.
 */
final class PpeLimitService
{
    public function __construct(private readonly GarmentLifecycleService $lifecycle) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function alerts(?string $customerId, bool $limitReachedOnly = false): array
    {
        $ratio = $limitReachedOnly ? '1' : (string) config('industrial_laundry.ppe.warning_ratio', '0.9');

        return Garment::query()
            ->whereNotNull('max_certified_wash_cycles_limit')
            ->where('max_certified_wash_cycles_limit', '>', 0)
            ->where('current_lifecycle_state', '!=', LifecycleState::CondemnedRag->value)
            ->whereRaw('wash_cycles_count >= max_certified_wash_cycles_limit * CAST(? AS NUMERIC)', [$ratio])
            ->when($customerId !== null, fn (Builder $query) => $query->where('assigned_customer_id', $customerId))
            ->orderByRaw('wash_cycles_count - max_certified_wash_cycles_limit DESC')
            ->orderBy('epc_uid_rfid_hex')
            ->get()
            ->map(fn (Garment $garment): array => [
                'id' => (string) $garment->id,
                'epc_uid_rfid_hex' => $garment->epc_uid_rfid_hex,
                'article_type' => $garment->article_type->value,
                'assigned_customer_id' => $garment->assigned_customer_id,
                'assigned_wearer_employee_name' => $garment->assigned_wearer_employee_name,
                'assigned_locker_code' => $garment->assigned_locker_code,
                'ppe_certification_standard' => $garment->ppe_certification_standard,
                'ppe_category' => $garment->ppe_category,
                'wash_cycles_count' => $garment->wash_cycles_count,
                'max_certified_wash_cycles_limit' => $garment->max_certified_wash_cycles_limit,
                'remaining_washes' => $garment->remainingWashes(),
                'current_lifecycle_state' => $garment->current_lifecycle_state->value,
                'is_reissue_blocked' => $garment->is_reissue_blocked,
                'status' => $garment->wash_cycles_count >= (int) $garment->max_certified_wash_cycles_limit ? 'limit_reached' : 'near_limit',
            ])
            ->values()
            ->all();
    }

    /**
     * @return Garment The garment, or the replacement when the action is to replace it.
     */
    public function resolve(Garment $garment, PpeAction $action, ?string $replacementEpc, ?string $reason, ?string $userId): Garment
    {
        if ($garment->current_lifecycle_state === LifecycleState::CondemnedRag) {
            throw new IndustrialLaundryException('garment_condemned');
        }

        if (! $garment->hasWashLimit() || (! $garment->is_reissue_blocked && $garment->wash_cycles_count < (int) $garment->max_certified_wash_cycles_limit)) {
            throw new IndustrialLaundryException('no_ppe_alert');
        }

        return DB::transaction(fn (): Garment => match ($action) {
            PpeAction::Mend => $this->mend($garment, $reason, $userId),
            PpeAction::Condemn => $this->condemn($garment, $reason ?? 'ppe_wash_limit_reached', GarmentEventType::Condemned, $userId),
            PpeAction::Replace => $this->replace($garment, $replacementEpc, $userId),
        });
    }

    /**
     * A garment back from mending is issued again, unless it is still past its wash limit.
     */
    public function completeMending(Garment $garment, ?string $userId): Garment
    {
        if ($garment->current_lifecycle_state !== LifecycleState::InMending) {
            throw new IndustrialLaundryException('garment_not_in_mending');
        }

        if ($garment->hasWashLimit() && $garment->wash_cycles_count >= (int) $garment->max_certified_wash_cycles_limit) {
            throw new IndustrialLaundryException('ppe_limit_still_reached');
        }

        $garment->is_reissue_blocked = false;

        return $this->lifecycle->move($garment, LifecycleState::CleanInStock, GarmentEventType::Released, Carbon::now(), ['userId' => $userId, 'note' => 'mending_completed']);
    }

    private function mend(Garment $garment, ?string $reason, ?string $userId): Garment
    {
        $garment->is_reissue_blocked = true;

        if ($garment->current_lifecycle_state === LifecycleState::InMending) {
            $garment->forceFill(['mending_or_condemn_reason' => $reason ?? 'ppe_wash_limit_reached', 'updated_by' => $userId])->save();

            return $garment;
        }

        return $this->lifecycle->move($garment, LifecycleState::InMending, GarmentEventType::SentToMending, Carbon::now(), ['reason' => $reason ?? 'ppe_wash_limit_reached', 'userId' => $userId]);
    }

    private function condemn(Garment $garment, string $reason, GarmentEventType $event, ?string $userId): Garment
    {
        $garment->is_reissue_blocked = true;

        return $this->lifecycle->move($garment, LifecycleState::CondemnedRag, $event, Carbon::now(), ['reason' => $reason, 'userId' => $userId]);
    }

    private function replace(Garment $garment, ?string $replacementEpc, ?string $userId): Garment
    {
        $epc = $replacementEpc === null ? null : TagReadFilter::normalizeEpc($replacementEpc);

        if ($epc === null) {
            throw new IndustrialLaundryException('replacement_epc_required');
        }

        if (Garment::query()->withTrashed()->where('epc_uid_rfid_hex', $epc)->exists()) {
            throw new IndustrialLaundryException('epc_taken', 422, ['epc' => $epc]);
        }

        $replacement = Garment::query()->create([
            'tenant_id' => $garment->tenant_id,
            'epc_uid_rfid_hex' => $epc,
            'article_type' => $garment->article_type->value,
            'product_id' => $garment->product_id,
            'description' => $garment->description,
            'assigned_customer_id' => $garment->assigned_customer_id,
            'assigned_wearer_employee_name' => $garment->assigned_wearer_employee_name,
            'assigned_locker_code' => $garment->assigned_locker_code,
            'max_certified_wash_cycles_limit' => $garment->max_certified_wash_cycles_limit,
            'ppe_certification_standard' => $garment->ppe_certification_standard,
            'ppe_category' => $garment->ppe_category,
            'wash_cycles_count' => 0,
            'current_lifecycle_state' => LifecycleState::CleanInStock->value,
            'created_by' => $userId,
        ]);
        $this->lifecycle->record($replacement, GarmentEventType::Registered, Carbon::now(), null, LifecycleState::CleanInStock, ['userId' => $userId, 'note' => (string) $garment->epc_uid_rfid_hex]);

        $this->lifecycle->record($garment, GarmentEventType::ReplacementApproved, Carbon::now(), null, null, ['userId' => $userId, 'note' => $epc]);
        $garment->forceFill(['assigned_wearer_employee_name' => null, 'assigned_locker_code' => null])->save();
        $this->condemn($garment, 'ppe_replaced', GarmentEventType::Condemned, $userId);

        return $replacement;
    }
}
