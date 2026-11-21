<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\VerifyPublicQuoteAccessCommand;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;

final class VerifyPublicQuoteAccessHandler
{
    public function __construct(private readonly PublicQuoteGate $gate) {}

    /** @return string the session token the portal sends back in X-Proposal-Session */
    public function handle(VerifyPublicQuoteAccessCommand $command): string
    {
        return $this->gate->verifyPin($command->token, $command->pin);
    }
}
