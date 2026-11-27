<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows\Pain002;

use Modules\Treasury\Models\PaymentBatch;

/**
 * What applying a pain.002 report did to a payment batch.
 */
final readonly class Pain002Result
{
    /**
     * @param  list<array{end_to_end_id: ?string, instruction_id: ?string, status: ?string, reason_code: ?string, additional_info: ?string, line_status: string}>  $transactions
     */
    public function __construct(
        public PaymentBatch $batch,
        public Pain002Report $report,
        public int $accepted,
        public int $rejected,
        public int $pending,
        public array $transactions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => (string) $this->batch->id,
            'batch_reference' => (string) $this->batch->batch_reference,
            'batch_status' => (string) $this->batch->status,
            'group_status' => $this->report->groupStatus,
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'pending' => $this->pending,
            'transactions' => $this->transactions,
        ];
    }
}
