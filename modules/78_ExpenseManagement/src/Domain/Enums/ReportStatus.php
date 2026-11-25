<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

/**
 * Life cycle of an expense report. Draft -> Submitted -> (UnderReview) -> Approved | Rejected; Approved -> Posted ->
 * Reimbursed. A rejected report returns to editing (Draft). Cancelled is final.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Reimbursed = 'reimbursed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected, self::Cancelled],
            self::UnderReview => [self::Approved, self::Rejected, self::Cancelled],
            self::Rejected => [self::Draft, self::Submitted, self::Cancelled],
            self::Approved => [self::Posted, self::Cancelled],
            self::Posted => [self::Reimbursed],
            self::Reimbursed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * Lines, mileage claims and per-diems may change only before the report is under review.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
