<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

/**
 * Kanban stages of a candidate application, declared in pipeline order.
 *
 * The transition table lives here so that actions, resources and tests share the same rules.
 */
enum ApplicationStage: string
{
    case Applied = 'applied';
    case HrScreening = 'hr_screening';
    case TechnicalInterview = 'technical_interview';
    case ManagerInterview = 'manager_interview';
    case OfferExtended = 'offer_extended';
    case Hired = 'hired';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Stages that tenants may switch off in the pipeline settings.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return [self::HrScreening, self::TechnicalInterview, self::ManagerInterview, self::OfferExtended];
    }

    /**
     * Stages on which an interview scorecard can be submitted.
     *
     * @return list<string>
     */
    public static function interviewStageValues(): array
    {
        return [self::HrScreening->value, self::TechnicalInterview->value, self::ManagerInterview->value];
    }

    public function canBeDisabled(): bool
    {
        return in_array($this, self::configurable(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Hired || $this === self::Rejected;
    }

    /**
     * Whether a `move` command may bring an application from this stage to the target stage.
     *
     * Pipeline stages move freely forward and backward between each other, any non-terminal stage can be rejected,
     * a rejected application can only be reopened to `applied` and `hired` is reachable only through the hire command.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($target === $this || $target === self::Hired || $this === self::Hired) {
            return false;
        }

        if ($this === self::Rejected) {
            return $target === self::Applied;
        }

        return true;
    }
}
