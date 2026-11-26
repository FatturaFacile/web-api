<?php

declare(strict_types=1);

namespace App\Contracts\Sms;

use RuntimeException;

/**
 * The SMS could not be delivered (gateway not configured, unreachable or refused the message).
 */
final class SmsDeliveryException extends RuntimeException {}
