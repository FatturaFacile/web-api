<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseReceiptUploaded;
use App\Jobs\Expenses\ProcessReceiptUploadJob;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttachReceiptAction
{
    /**
     * Store and link a receipt file (photo or PDF) to the expense.
     */
    public function execute(Expense $expense, UploadedFile $file, bool $isReceipt = true): ExpenseAttachment
    {
        return DB::transaction(function () use ($expense, $file, $isReceipt): ExpenseAttachment {
            $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $fileName = (string) Str::uuid().'.'.$extension;
            $relativeDirectory = "tenants/{$expense->tenant_id}/expenses/{$expense->id}";

            $path = $file->storeAs($relativeDirectory, $fileName, ['disk' => $disk]);
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
            $size = $file->getSize() ?: 0;
            $userId = Auth::id() ?? $expense->created_by;

            // 1. Create polymorphic platform attachment
            /** @var Attachment $platformAttachment */
            $platformAttachment = Attachment::create([
                'tenant_id' => $expense->tenant_id,
                'attachable_type' => Expense::class,
                'attachable_id' => $expense->id,
                'uploaded_by' => $userId,
                'original_name' => $originalName,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);

            // 2. Create dedicated expense attachment
            /** @var ExpenseAttachment $expenseAttachment */
            $expenseAttachment = ExpenseAttachment::create([
                'tenant_id' => $expense->tenant_id,
                'expense_id' => $expense->id,
                'attachment_id' => $platformAttachment->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size' => $size,
                'is_receipt' => $isReceipt,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Dispatch background optimization job with afterCommit protection
            ProcessReceiptUploadJob::dispatch($expenseAttachment)->afterCommit();

            event(new ExpenseReceiptUploaded($expense, $expenseAttachment));

            return $expenseAttachment;
        });
    }
}
