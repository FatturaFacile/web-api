<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;

/**
 * Registers and edits tagged garments. The wash cycle counter and the lifecycle are not editable here: the tunnel gates and the
 * PPE decisions move them.
 */
final class GarmentService
{
    public function __construct(private readonly GarmentLifecycleService $lifecycle) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(string $tenantId, array $data, ?string $userId): Garment
    {
        $epc = TagReadFilter::normalizeEpc((string) ($data['epc_uid_rfid_hex'] ?? ''));

        if ($epc === null) {
            throw new IndustrialLaundryException('epc_invalid');
        }

        if (Garment::query()->withTrashed()->where('epc_uid_rfid_hex', $epc)->exists()) {
            throw new IndustrialLaundryException('epc_taken', 422, ['epc' => $epc]);
        }

        return DB::transaction(function () use ($tenantId, $data, $epc, $userId): Garment {
            $garment = Garment::query()->create($this->attributes($data) + [
                'tenant_id' => $tenantId,
                'epc_uid_rfid_hex' => $epc,
                'current_lifecycle_state' => LifecycleState::CleanInStock->value,
                'created_by' => $userId,
            ]);

            $this->lifecycle->record($garment, GarmentEventType::Registered, Carbon::now(), null, LifecycleState::CleanInStock, ['userId' => $userId]);

            return $garment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Garment $garment, array $data, ?string $userId): Garment
    {
        if ($garment->current_lifecycle_state === LifecycleState::CondemnedRag) {
            throw new IndustrialLaundryException('garment_condemned');
        }

        $garment->forceFill($this->attributes($data) + ['updated_by' => $userId]);

        // Raising the certified limit (a new manufacturer certificate) lifts the block of a garment that is not at it any more.
        if ($garment->is_reissue_blocked && $garment->current_lifecycle_state !== LifecycleState::InMending && (! $garment->hasWashLimit() || $garment->wash_cycles_count < (int) $garment->max_certified_wash_cycles_limit)) {
            $garment->is_reissue_blocked = false;
        }

        $garment->save();

        return $garment->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        foreach (['thermal_barcode', 'article_type', 'product_id', 'description', 'assigned_customer_id', 'max_certified_wash_cycles_limit', 'ppe_certification_standard', 'ppe_category'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (isset($attributes['article_type']) && ArticleType::tryFrom((string) $attributes['article_type']) === null) {
            throw new IndustrialLaundryException('article_type_invalid');
        }

        return $attributes;
    }
}
