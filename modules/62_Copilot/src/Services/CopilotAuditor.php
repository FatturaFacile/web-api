<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the Copilot events to the immutable audit ledger (module code COPILOT). The ledger keeps what was done and by
 * whom, not what was typed: the text of a prompt never enters it.
 */
final readonly class CopilotAuditor
{
    public const string Module = 'COPILOT';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $subject, array $details = []): void
    {
        $this->ledger->record('COPILOT_'.strtoupper($action), $subject, null, $details, self::Module);
    }
}
