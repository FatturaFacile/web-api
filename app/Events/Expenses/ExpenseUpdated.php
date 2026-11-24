<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Expense;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExpenseUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Expense $expense,
        public readonly array $changedAttributes = []
    ) {}
}
