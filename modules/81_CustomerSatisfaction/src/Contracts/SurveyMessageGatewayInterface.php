<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Contracts;

use Modules\CustomerSatisfaction\Domain\Enums\SurveyChannel;

/**
 * Delivers a survey invitation on one channel (email, SMS or WhatsApp). The default bindings send email and log SMS and
 * WhatsApp; plug a provider adapter in by rebinding this interface.
 */
interface SurveyMessageGatewayInterface
{
    public function supports(SurveyChannel $channel): bool;

    /**
     * @throws \Throwable when the provider refuses the message
     */
    public function send(SurveyChannel $channel, string $recipient, string $subject, string $body): void;
}
