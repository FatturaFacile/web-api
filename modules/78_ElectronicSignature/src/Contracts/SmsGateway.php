<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Contracts;

use Modules\ElectronicSignature\Exceptions\SignatureException;

/**
 * Sends the one-time codes of the identity check by SMS.
 */
interface SmsGateway
{
    /**
     * @param  string  $to  E.164 number
     * @param  bool  $sandbox  true for a sandbox provider configuration: the message is only logged, nothing leaves the server
     *
     * @throws SignatureException when no gateway is configured outside the sandbox or the gateway refuses
     */
    public function send(string $to, string $message, bool $sandbox): void;
}
