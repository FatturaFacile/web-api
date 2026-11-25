<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

/**
 * Full ECR -> ECO -> ECN workflow stages.
 */
enum EcoWorkflowStage: string
{
    case Draft = 'Draft';
    case EcrSubmitted = 'EcrSubmitted';
    case UnderEvaluation = 'UnderEvaluation';
    case EcoApproved = 'EcoApproved';
    case EcnReleased = 'EcnReleased';
    case Closed = 'Closed';
    case Rejected = 'Rejected';
    case Cancelled = 'Cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function legacyStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::EcrSubmitted, self::UnderEvaluation => 'in_review',
            self::EcoApproved => 'approved',
            self::EcnReleased, self::Closed => 'implemented',
            self::Rejected => 'rejected',
            self::Cancelled => 'cancelled',
        };
    }

    public static function fromLegacyStatus(string $status): self
    {
        return match ($status) {
            'in_review' => self::EcrSubmitted,
            'approved' => self::EcoApproved,
            'implemented' => self::EcnReleased,
            'rejected' => self::Rejected,
            'cancelled' => self::Cancelled,
            default => self::Draft,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Draft => in_array($target, [self::EcrSubmitted, self::Cancelled], true),
            self::EcrSubmitted => in_array($target, [self::UnderEvaluation, self::EcoApproved, self::Rejected, self::Cancelled], true),
            self::UnderEvaluation => in_array($target, [self::EcoApproved, self::Rejected, self::Cancelled], true),
            self::EcoApproved => in_array($target, [self::EcnReleased, self::Cancelled], true),
            self::EcnReleased => $target === self::Closed,
            self::Closed, self::Rejected, self::Cancelled => false,
        };
    }
}
