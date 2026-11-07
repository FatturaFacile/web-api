<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Modules\Associations\Enums\DeliveryChannel;

/**
 * One attempt to deliver a notice or minutes to a person, with date, time and channel: the proof of the call.
 *
 * @property string $status
 * @property DeliveryChannel $channel
 */
final class AssociationDelivery extends AssociationEntry
{
    public const string Sent = 'sent';

    public const string Printed = 'printed';

    public const string Simulated = 'simulated';

    public const string Failed = 'failed';

    public const string NoRecipient = 'no_recipient';

    /**
     * Statuses that prove that the document reached the person. A simulated send left nothing the application.
     */
    public const array Reached = [self::Sent, self::Printed];

    public const string MeetingNotice = 'meeting_notice';

    public const string MinutesCopy = 'minutes';

    protected $table = 'assoc_deliveries';

    protected function casts(): array
    {
        return ['channel' => DeliveryChannel::class, 'recipient' => 'encrypted', 'attempted_at' => 'datetime'];
    }
}
