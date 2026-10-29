<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExpensesExportedToAccountant
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<string>  $expenseIds
     * @param  array<string, mixed>  $filterCriteria
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?User $actor,
        public readonly array $expenseIds,
        public readonly int $totalCount,
        public readonly float $totalAmount,
        public readonly array $filterCriteria = []
    ) {}
}
