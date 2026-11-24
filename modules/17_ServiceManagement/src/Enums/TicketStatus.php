<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

/**
 * Status of a helpdesk ticket. Assigned, InProgress and Escalated are working states of an open ticket written by the
 * earlier ticket API: they behave as Open in the transitions.
 */
enum TicketStatus: string
{
    case New = 'new';
    case Open = 'open';
    case PendingCustomer = 'pending_customer';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Escalated = 'escalated';

    /**
     * Statuses a client may set through the helpdesk endpoints.
     *
     * @return list<string>
     */
    public static function settableValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, [self::New, self::Open, self::PendingCustomer, self::OnHold, self::Resolved, self::Closed]);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isActive())
        ));
    }

    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Open;
    }

    /**
     * Not resolved and not closed: the ticket still needs work.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /**
     * Open and its working states.
     */
    public function isWorking(): bool
    {
        return in_array($this, [self::Open, self::Assigned, self::InProgress, self::Escalated], true);
    }

    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return false;
        }

        $allowed = match (true) {
            $this === self::New => [self::Open, self::PendingCustomer, self::OnHold, self::Resolved, self::Closed],
            $this->isWorking() => [self::Open, self::PendingCustomer, self::OnHold, self::Resolved, self::Closed],
            $this === self::PendingCustomer => [self::Open, self::OnHold, self::Resolved, self::Closed],
            $this === self::OnHold => [self::Open, self::PendingCustomer, self::Resolved, self::Closed],
            $this === self::Resolved => [self::Closed, self::Open],
            default => [self::Open],
        };

        return in_array($target, $allowed, true);
    }

    /**
     * Moving back to Open from Resolved or Closed is a reopen, bound to the reopen window.
     */
    public function isReopenTo(self $target): bool
    {
        return ! $this->isActive() && $target === self::Open;
    }
}
