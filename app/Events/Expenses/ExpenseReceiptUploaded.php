<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExpenseReceiptUploaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Expense $expense,
        public readonly ExpenseAttachment $attachment
    ) {}
}
