<?php

declare(strict_types=1);

namespace Modules\HumanResources\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\HumanResources\Services\EmployeeDocumentService;

/**
 * Erases the personnel documents of a company whose retention date has passed (file and row). The log holds a count
 * and no name.
 */
final class PurgeExpiredEmployeeDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the purge of the company.
     */
    public function handle(EmployeeDocumentService $documents): void
    {
        $erased = $documents->purgeExpired($this->tenantId);

        if ($erased > 0) {
            Log::info('Employee documents erased at the end of their retention.', ['tenant_id' => $this->tenantId, 'count' => $erased]);
        }
    }
}
