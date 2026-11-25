<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Services\Banking\ReconciliationSettings;
use Modules\Treasury\Services\TreasuryLedgerAccounts;

/**
 * Tuning of the smart reconciliation engine. The amount tolerance, the maximum bank fee and the permission of partial
 * settlements come from the reconciliation preferences already used by the automatic matching; the engine adds:
 *
 * - `banking.reconciliation.fx_tolerance_percent`  largest exchange difference (percent of the balance) booked as FX;
 * - `banking.reconciliation.name_similarity`       minimum similarity (0-1) of normalised counterpart names;
 * - `banking.reconciliation.min_confidence`        proposals below this confidence are not stored;
 * - `banking.reconciliation.auto_apply_confidence` confidence from which a unique proposal is applied automatically.
 */
final readonly class SmartSettings
{
    public const float DEFAULT_FX_TOLERANCE_PERCENT = 2.0;

    public const float DEFAULT_NAME_SIMILARITY = 0.85;

    public const int DEFAULT_MIN_CONFIDENCE = 50;

    public const int DEFAULT_AUTO_APPLY_CONFIDENCE = 95;

    public function __construct(
        public ReconciliationSettings $base,
        public float $fxTolerancePercent = self::DEFAULT_FX_TOLERANCE_PERCENT,
        public float $nameSimilarity = self::DEFAULT_NAME_SIMILARITY,
        public int $minConfidence = self::DEFAULT_MIN_CONFIDENCE,
        public int $autoApplyConfidence = self::DEFAULT_AUTO_APPLY_CONFIDENCE,
    ) {}

    public static function forTenant(string $tenantId): self
    {
        $value = static fn (string $key): mixed => TreasuryLedgerAccounts::preference($tenantId, 'banking', 'reconciliation', $key);

        return new self(
            ReconciliationSettings::forTenant($tenantId),
            (float) ($value('fx_tolerance_percent') ?? self::DEFAULT_FX_TOLERANCE_PERCENT),
            (float) ($value('name_similarity') ?? self::DEFAULT_NAME_SIMILARITY),
            (int) ($value('min_confidence') ?? self::DEFAULT_MIN_CONFIDENCE),
            (int) ($value('auto_apply_confidence') ?? self::DEFAULT_AUTO_APPLY_CONFIDENCE),
        );
    }
}
