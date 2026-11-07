<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsRetrievalRun;
use Throwable;

/**
 * Downloads what the customs channel issued in a period. A document that fails stays on the run as an error and does
 * not stop the others; it comes back on the next run, because the window overlaps.
 */
final class AssessmentRetrievalService
{
    public function __construct(
        private readonly CustomsGatewayManager $gateways,
        private readonly AssessmentArchive $archive,
    ) {}

    public function run(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): CustomsRetrievalRun
    {
        $gateway = $this->gateways->for($tenant);

        $run = new CustomsRetrievalRun;
        $run->id = (string) Str::uuid7();
        $run->tenant_id = $tenant->id;
        $run->fill([
            'gateway' => $gateway->name(),
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->save();

        $errors = [];
        $stored = 0;
        $duplicates = 0;

        try {
            $references = $gateway->listAssessments($tenant, $from, $to);
        } catch (CustomsException $e) {
            return $this->finish($run, 'failed', 0, 0, 0, [['reference' => null, 'error' => $e->getMessage()]]);
        }

        foreach ($references as $reference) {
            if ($this->isArchived($tenant, $reference)) {
                $duplicates++;

                continue;
            }

            try {
                $result = $this->archive->store($tenant, $gateway->fetchAssessment($tenant, $reference), $gateway->name());
                $result['is_new'] ? $stored++ : $duplicates++;
            } catch (Throwable $e) {
                report($e);
                $errors[] = ['reference' => "{$reference->mrn}/{$reference->version}/{$reference->documentType->value}", 'error' => $e->getMessage()];
            }
        }

        return $this->finish($run, $errors === [] ? 'completed' : 'partial', count($references), $stored, $duplicates, $errors);
    }

    private function isArchived(Tenant $tenant, AssessmentReference $reference): bool
    {
        return CustomsAssessmentDecision::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('mrn', $reference->mrn)
            ->where('version', $reference->version)
            ->where('document_type', $reference->documentType->value)
            ->exists();
    }

    /**
     * @param  list<array{reference: string|null, error: string}>  $errors
     */
    private function finish(CustomsRetrievalRun $run, string $status, int $listed, int $stored, int $duplicates, array $errors): CustomsRetrievalRun
    {
        $run->update([
            'status' => $status,
            'listed_count' => $listed,
            'stored_count' => $stored,
            'duplicate_count' => $duplicates,
            'failed_count' => count($errors),
            'errors' => $errors === [] ? null : $errors,
            'finished_at' => now(),
        ]);

        return $run;
    }
}
