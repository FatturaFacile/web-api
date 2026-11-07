<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * Life cycle of a freight/customs dossier.
 *
 * Manual changes (PUT /customs/dossiers/{id}) follow the forward flow below; a dossier can always be kept in its
 * current status. Quoted, Delivered and Invoiced are also set by the system (a quote is sent, the last packages are
 * delivered, the dossier is invoiced); Invoiced can only be reached by invoicing the dossier.
 *
 *   open       -> quoted, in_transit, cancelled
 *   quoted     -> open (quote withdrawn), in_transit, cancelled
 *   in_transit -> delivered, cancelled
 *   delivered  -> closed
 *   invoiced   -> closed
 *   closed, cancelled: final
 */
enum DossierStatus: string
{
    case Open = 'open';
    case Quoted = 'quoted';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self> statuses a user may move the dossier to from this one
     */
    public function manualTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Quoted, self::InTransit, self::Cancelled],
            self::Quoted => [self::Open, self::InTransit, self::Cancelled],
            self::InTransit => [self::Delivered, self::Cancelled],
            self::Delivered, self::Invoiced => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionManuallyTo(self $target): bool
    {
        return $target === $this || in_array($target, $this->manualTransitions(), true);
    }
}
