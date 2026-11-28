<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\SianCuaaIdentifier;

/**
 * The MASAF SIAN rules of the telematic oil register: no blending of DOP / IGP oils with anything else, segregation of organic oil, a tank cannot
 * overflow, the origin designation of a blend, the CUAA of the counterparty and the six day deadline.
 */
final class SianComplianceService
{
    public function __construct(private readonly int $deadlineDays = 6, private readonly int $alertDays = 2) {}

    public static function fromConfig(): self
    {
        return new self((int) config('olive_mill.sian_deadline_days', 6), (int) config('olive_mill.sian_alert_days', 2));
    }

    /**
     * The category of a tank after oil of another category is poured in. A blend of plain categories takes the lowest grade (downgrade).
     *
     * @return array{category: OilCategory, downgraded: bool}
     *
     * @throws OliveMillException dop_igp_mixing, bio_segregation
     */
    public function mixCategory(?OilCategory $current, float $currentKg, OilCategory $incoming): array
    {
        if ($current === null || $currentKg <= 0.0005 || $current === $incoming) {
            return ['category' => $incoming, 'downgraded' => false];
        }

        if ($current->isSegregated() || $incoming->isSegregated()) {
            throw OliveMillException::because($current === OilCategory::Bio || $incoming === OilCategory::Bio ? 'bio_segregation' : 'dop_igp_mixing', [
                'tank' => $current->value,
                'incoming' => $incoming->value,
            ]);
        }

        $grade = min($current->grade(), $incoming->grade());
        $result = OilCategory::ofGrade($grade);

        return ['category' => $result, 'downgraded' => $result !== $incoming || $result !== $current];
    }

    /**
     * The origin designation of a blend: the widest of the two (Italy < EU < non-EU); a specific DOP origin is never blended.
     *
     * @throws OliveMillException origin_designation_mixing
     */
    public function mixOrigin(?OriginDesignation $current, float $currentKg, OriginDesignation $incoming): OriginDesignation
    {
        if ($current === null || $currentKg <= 0.0005 || $current === $incoming) {
            return $incoming;
        }

        if ($current === OriginDesignation::DopSpecifica || $incoming === OriginDesignation::DopSpecifica) {
            throw OliveMillException::because('origin_designation_mixing');
        }

        $rank = static fn (OriginDesignation $origin): int => match ($origin) {
            OriginDesignation::Italia => 1,
            OriginDesignation::Ue => 2,
            default => 3,
        };

        return $rank($current) >= $rank($incoming) ? $current : $incoming;
    }

    /**
     * @throws OliveMillException tank_capacity_exceeded
     */
    public function assertCapacity(float $capacityKg, float $stockKg, float $incomingKg): void
    {
        if ($stockKg + $incomingKg > $capacityKg + 0.0005) {
            throw OliveMillException::because('tank_capacity_exceeded', ['capacity' => $capacityKg, 'stock' => $stockKg, 'incoming' => $incomingKg]);
        }
    }

    /**
     * @throws OliveMillException insufficient_tank_stock
     */
    public function assertStock(float $stockKg, float $requestedKg): void
    {
        if ($requestedKg > $stockKg + 0.0005) {
            throw OliveMillException::because('insufficient_tank_stock', ['stock' => $stockKg, 'requested' => $requestedKg]);
        }
    }

    /**
     * Causals that name a counterparty (farmer, buyer or seller) must carry a valid CUAA.
     *
     * @throws OliveMillException counterparty_required, invalid_cuaa
     */
    public function assertCounterparty(SianCausalCode $code, ?string $cuaa): void
    {
        if (! in_array($code, [SianCausalCode::C02, SianCausalCode::C03, SianCausalCode::S01, SianCausalCode::S02], true)) {
            return;
        }

        if ($cuaa === null || trim($cuaa) === '') {
            throw OliveMillException::because('counterparty_required', ['code' => $code->value]);
        }

        SianCuaaIdentifier::fromString($cuaa);
    }

    public function deadline(CarbonInterface $operationDate): CarbonImmutable
    {
        return CarbonImmutable::parse($operationDate->toDateString())->addDays($this->deadlineDays);
    }

    /**
     * Whole days left until the deadline (negative once it is over).
     */
    public function daysLeft(CarbonInterface $deadline, ?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::today())->toDateString());

        return (int) $today->diffInDays(CarbonImmutable::parse($deadline->toDateString()), false);
    }

    /**
     * ok, due_soon (at most the alert days left) or overdue.
     */
    public function alertLevel(CarbonInterface $deadline, ?CarbonInterface $today = null): string
    {
        $left = $this->daysLeft($deadline, $today);

        return match (true) {
            $left < 0 => 'overdue',
            $left <= $this->alertDays => 'due_soon',
            default => 'ok',
        };
    }
}
