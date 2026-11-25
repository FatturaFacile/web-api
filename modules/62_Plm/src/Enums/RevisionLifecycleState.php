<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

/**
 * Lifecycle state machine of a ProductEngineeringItemRevision.
 */
enum RevisionLifecycleState: string
{
    case InDesignPrototype = 'InDesign_Prototype';
    case UnderReview = 'UnderReview';
    case ReleasedForProduction = 'ReleasedForProduction';
    case PhaseOutUseUpStock = 'PhaseOut_UseUpStock';
    case ObsoleteBlocked = 'ObsoleteBlocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Maps to the legacy 3-state status column (`draft`, `released`, `obsolete`).
     */
    public function legacyStatus(): string
    {
        return match ($this) {
            self::InDesignPrototype, self::UnderReview => 'draft',
            self::ReleasedForProduction, self::PhaseOutUseUpStock => 'released',
            self::ObsoleteBlocked => 'obsolete',
        };
    }

    public static function fromLegacyStatus(string $status): self
    {
        return match ($status) {
            'released' => self::ReleasedForProduction,
            'obsolete' => self::ObsoleteBlocked,
            default => self::InDesignPrototype,
        };
    }

    /**
     * Enforces strict lifecycle transition rules. Direct release to production is permitted
     * only for an initial item revision or via an approved ECO cutover.
     */
    public function canTransitionTo(self $target, bool $viaEcoRelease = false, bool $isInitialRelease = false): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::InDesignPrototype => match ($target) {
                self::UnderReview => true,
                self::ReleasedForProduction => $viaEcoRelease || $isInitialRelease,
                default => false,
            },
            self::UnderReview => match ($target) {
                self::InDesignPrototype => true,
                self::ReleasedForProduction => $viaEcoRelease || $isInitialRelease,
                default => false,
            },
            self::ReleasedForProduction => in_array($target, [self::PhaseOutUseUpStock, self::ObsoleteBlocked], true),
            self::PhaseOutUseUpStock => $target === self::ObsoleteBlocked,
            self::ObsoleteBlocked => false,
        };
    }
}
