<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Enums;

/**
 * Status of the onboarding of a supplier.
 *
 * invited ─► registration_submitted ─► documents_required ◄─► under_review ─► approved ─► blocked
 *                  │                          │                    │                          │
 *                  └─► under_review           └─► rejected         └─► rejected               └─► under_review
 *                  └─► documents_required
 *
 * A rejected or blocked supplier goes back to review, never straight to approved.
 */
enum OnboardingStatus: string
{
    case Invited = 'invited';
    case RegistrationSubmitted = 'registration_submitted';
    case DocumentsRequired = 'documents_required';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Blocked = 'blocked';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Invited => [self::RegistrationSubmitted, self::Rejected],
            self::RegistrationSubmitted => [self::DocumentsRequired, self::UnderReview, self::Rejected],
            self::DocumentsRequired => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::DocumentsRequired, self::Approved, self::Rejected],
            self::Approved => [self::Blocked],
            self::Rejected => [self::UnderReview],
            self::Blocked => [self::UnderReview],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Whether the supplier may still send registration data and documents from the portal.
     */
    public function acceptsSupplierInput(): bool
    {
        return in_array($this, [self::Invited, self::RegistrationSubmitted, self::DocumentsRequired], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
