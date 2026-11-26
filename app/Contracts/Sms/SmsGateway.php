<?php

declare(strict_types=1);

namespace App\Contracts\Sms;

/**
 * Sends a text message to a phone number: the delivery channel of the SMS second factor.
 */
interface SmsGateway
{
    /**
     * @param  string  $to  E.164 number
     *
     * @throws SmsDeliveryException when the gateway is not configured or refuses the message
     */
    public function send(string $to, string $message): void;
}
