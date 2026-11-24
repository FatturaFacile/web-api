<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Illuminate\Support\Facades\Mail;
use Modules\AgentCommissions\Exceptions\AgentCommissionException;
use Modules\AgentCommissions\Mail\CommissionStatementMail;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\StatementRenderer;
use Modules\AgentCommissions\Services\StatementService;

final class SendStatementHandler
{
    public function __construct(
        private readonly StatementRenderer $renderer,
        private readonly StatementService $statements,
    ) {}

    public function handle(SendStatementCommand $command): Statement
    {
        $statement = Statement::query()->with('lines')->findOrFail($command->statementId);
        $recipient = $command->recipient ?? AgentProfile::query()->with('representative')->find($statement->agent_profile_id)?->representative?->email;

        if ($recipient === null || $recipient === '') {
            throw new AgentCommissionException('The agent has no email address.', 'no_recipient');
        }

        Mail::to($recipient)->send(new CommissionStatementMail($statement, $this->renderer->pdf($statement, $command->locale), $command->locale));
        $this->statements->recordDispatch($statement, $recipient);

        return $statement->refresh();
    }
}
