<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

/**
 * Shape of a recipient on each channel, so a message is never addressed with free text: an e-mail address, a phone number
 * in international or national digits, or a Telegram chat id or @username.
 */
final class RecipientRules
{
    /**
     * Channels a message can leave on.
     *
     * @var list<string>
     */
    public const array Channels = ['email', 'whatsapp', 'telegram', 'sms'];

    /**
     * @return list<string>
     */
    public static function for(?string $channel): array
    {
        return match ($channel) {
            'email' => ['string', 'max:255', 'email:rfc'],
            'sms', 'whatsapp' => ['string', 'max:32', 'regex:/^\+?[0-9][0-9 ()\-]{4,28}$/'],
            'telegram' => ['string', 'max:64', 'regex:/^(-?[0-9]{1,20}|@[A-Za-z0-9_]{5,32})$/'],
            default => ['string', 'max:255'],
        };
    }
}
