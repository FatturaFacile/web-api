<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Domain\Enums\CareStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\CareType;
use Modules\DairyCheeseManufacturing\Domain\Enums\CheeseType;
use Modules\DairyCheeseManufacturing\Domain\Enums\DeliveryStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\InhibitorResult;
use Modules\DairyCheeseManufacturing\Domain\Enums\Jurisdiction;
use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;
use Modules\DairyCheeseManufacturing\Domain\Enums\MilkSession;
use Modules\DairyCheeseManufacturing\Domain\Enums\PricingComponent;
use Modules\DairyCheeseManufacturing\Domain\Enums\SettlementDocumentType;
use Modules\DairyCheeseManufacturing\Domain\Enums\SettlementStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\SubmissionStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\TankStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\VatBatchStatus;
use Modules\DairyCheeseManufacturing\Domain\Enums\WheelGrade;
use Modules\DairyCheeseManufacturing\Domain\Enums\WheelStatus;
use Modules\DairyCheeseManufacturing\Http\Resources\RackResource;
use Modules\DairyCheeseManufacturing\Http\Resources\TankResource;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;

/**
 * Enumerations (with the label keys of the frontend dictionary) and the small lists the forms need.
 */
final class LookupService
{
    public function __construct(private readonly CellarMapService $cellar) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'enums' => [
                'session' => MilkSession::options('session'),
                'tank_status' => TankStatus::options('tank_status'),
                'jurisdiction' => Jurisdiction::options('jurisdiction'),
                'delivery_status' => DeliveryStatus::options('delivery_status'),
                'inhibitor_result' => InhibitorResult::options('inhibitor_result'),
                'settlement_status' => SettlementStatus::options('settlement_status'),
                'vat_batch_status' => VatBatchStatus::options('vat_batch_status'),
                'wheel_status' => WheelStatus::options('wheel_status'),
                'wheel_grade' => WheelGrade::options('wheel_grade'),
                'care_type' => CareType::options('care_type'),
                'care_status' => CareStatus::options('care_status'),
                'authority' => Authority::options('authority'),
                'submission_status' => SubmissionStatus::options('submission_status'),
                'cheese_type' => CheeseType::options('cheese_type'),
                'pricing_component_type' => PricingComponent::options('pricing_component_type'),
                'lab_format' => LabFormat::options('lab_format'),
                'document_type' => SettlementDocumentType::options('document_type'),
            ],
            'cheese_types' => CheeseType::values(),
            'tanks' => TankResource::collection(MilkTank::query()->orderBy('code')->get())->resolve(),
            'breeders' => Breeder::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(static fn (Breeder $b): array => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
            'racks' => RackResource::collection($this->cellar->racks())->resolve(),
            'grids' => PricingGrid::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(static fn (PricingGrid $g): array => ['id' => $g->id, 'name' => $g->name])->all(),
        ];
    }
}
