<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\Sms\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Local and test gateway: nothing leaves the server. The number is masked; the message is logged only outside
 * production, because it carries a one-time code.
 */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info('sms.log', ['to' => PhoneNumber::mask($to), 'message' => app()->isProduction() ? '[hidden]' : $message]);
    }
}
