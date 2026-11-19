<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The customs file of a shipment as the trader follows it, derived from the declaration status and the assessment
 * decision (eVV) so that no second status has to be kept in step:
 *
 *   draft -> declared -> cleared -> archived_evv
 *
 * A cancelled declaration is outside the flow.
 */
enum CustomsWorkflowStage: string
{
    case Draft = 'draft';
    case Declared = 'declared';
    case Cleared = 'cleared';
    case ArchivedEvv = 'archived_evv';

    public static function resolve(DeclarationStatus $status, bool $hasAssessmentDecision): ?self
    {
        return match ($status) {
            DeclarationStatus::Cancelled => null,
            DeclarationStatus::Draft, DeclarationStatus::Validated, DeclarationStatus::Rejected => self::Draft,
            DeclarationStatus::Submitted => self::Declared,
            DeclarationStatus::Accepted => $hasAssessmentDecision ? self::ArchivedEvv : self::Cleared,
        };
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Declared],
            self::Declared => [self::Cleared],
            self::Cleared => [self::ArchivedEvv],
            self::ArchivedEvv => [],
        };
    }
}
