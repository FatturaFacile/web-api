<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\CustomerSatisfaction\Contracts\SurveyMessageGatewayInterface;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;

/**
 * Email goes through the configured mailer; SMS and WhatsApp have no provider wired by default and are written to the log
 * (rebind {@see SurveyMessageGatewayInterface} to plug a provider in).
 */
final class DefaultSurveyMessageGateway implements SurveyMessageGatewayInterface
{
    public function supports(SurveyChannel $channel): bool
    {
        return true;
    }

    public function send(SurveyChannel $channel, string $recipient, string $subject, string $body): void
    {
        if ($channel === SurveyChannel::Email) {
            Mail::raw($body, static function ($message) use ($recipient, $subject): void {
                $message->to($recipient)->subject($subject);
            });

            return;
        }

        Log::info('customer-satisfaction survey message', ['channel' => $channel->value, 'recipient' => $recipient, 'body' => $body]);
    }
}
