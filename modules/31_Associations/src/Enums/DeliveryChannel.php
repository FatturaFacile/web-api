<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Channels a notice can travel on. The letter is a printable PDF that the association hands over or posts.
 */
enum DeliveryChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Telegram = 'telegram';
    case Letter = 'letter';
}
