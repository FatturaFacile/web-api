<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\PackagePricingService;
use Modules\SkiOutdoorSchool\Events\PackageEquipmentReserved;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\PackageItem;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * The bundled add-ons of a lesson or a course enrolment (equipment rental, ARTVA kit, skipass). Replaces the whole set in one transaction,
 * defaults the VAT rate from the jurisdiction of the school and announces the equipment to the rental / inventory hook.
 */
final class PackageHandler
{
    public function __construct(private readonly PackagePricingService $pricing, private readonly SchoolSettingsService $settings) {}

    /**
     * @param  list<array<string, mixed>>  $items  item_type, description, quantity, days, unit_price_net, vat_rate, product_id
     * @return list<PackageItem>
     *
     * @throws SkiSchoolException owner_not_found, owner_invoiced
     */
    public function replaceForLesson(string $lessonId, array $items): array
    {
        return DB::transaction(function () use ($lessonId, $items): array {
            $lesson = SkiLesson::query()->whereKey($lessonId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('lesson_not_found', [], 404);

            if ($lesson->invoice_id !== null) {
                throw SkiSchoolException::because('owner_invoiced');
            }

            return $this->replace('lesson_id', 'LESSON', (string) $lesson->id, (string) $lesson->tenant_id, $items);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<PackageItem>
     *
     * @throws SkiSchoolException owner_not_found, owner_invoiced
     */
    public function replaceForEnrollment(string $enrollmentId, array $items): array
    {
        return DB::transaction(function () use ($enrollmentId, $items): array {
            $enrollment = CourseEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('enrollment_not_found', [], 404);

            if ($enrollment->invoice_id !== null) {
                throw SkiSchoolException::because('owner_invoiced');
            }

            return $this->replace('enrollment_id', 'ENROLLMENT', (string) $enrollment->id, (string) $enrollment->tenant_id, $items);
        });
    }

    /**
     * @throws SkiSchoolException item_not_found, invalid_item_transition
     */
    public function setStatus(string $itemId, PackageItemStatus $status): PackageItem
    {
        $item = PackageItem::query()->find($itemId) ?? throw SkiSchoolException::because('item_not_found', [], 404);
        $allowed = [
            PackageItemStatus::RESERVED->value => [PackageItemStatus::DELIVERED, PackageItemStatus::CANCELLED],
            PackageItemStatus::DELIVERED->value => [PackageItemStatus::RETURNED],
        ];

        if (! in_array($status, $allowed[$item->status->value] ?? [], true)) {
            throw SkiSchoolException::because('invalid_item_transition', ['from' => $item->status->value, 'to' => $status->value]);
        }

        $item->update(['status' => $status->value]);

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<PackageItem>
     */
    private function replace(string $column, string $ownerType, string $ownerId, string $tenantId, array $items): array
    {
        PackageItem::query()->where($column, $ownerId)->get()->each->delete();
        $jurisdiction = $this->settings->current()->jurisdiction;
        $created = [];
        $equipment = [];

        foreach ($items as $row) {
            $type = PackageItemType::from((string) $row['item_type']);
            $item = PackageItem::query()->create([
                'tenant_id' => $tenantId,
                $column => $ownerId,
                'item_type' => $type->value,
                'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                'quantity' => (float) ($row['quantity'] ?? 1),
                'days' => (int) ($row['days'] ?? 1),
                'unit_price_net' => (float) ($row['unit_price_net'] ?? 0),
                'vat_rate' => isset($row['vat_rate']) && $row['vat_rate'] !== '' ? (float) $row['vat_rate'] : $this->pricing->addOnRate($jurisdiction, $type),
                'product_id' => isset($row['product_id']) && $row['product_id'] !== '' ? (string) $row['product_id'] : null,
                'status' => PackageItemStatus::RESERVED->value,
            ]);
            $created[] = $item;

            if ($type->isEquipment()) {
                $equipment[] = ['item_id' => (string) $item->id, 'item_type' => $type->value, 'quantity' => (float) $item->quantity, 'days' => $item->days, 'product_id' => $item->product_id];
            }
        }

        if ($equipment !== []) {
            event(new PackageEquipmentReserved($ownerType, $ownerId, $equipment));
        }

        return $created;
    }
}
