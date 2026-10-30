<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AccountStatementMail;
use App\Models\Contact;
use App\Models\DocumentEmail;
use App\Models\Tenant;
use App\Services\Crm\ContactInsights;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAccountStatementEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $documentEmailId, public readonly string $asOf)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, ContactInsights $insights): void
    {
        $email = DocumentEmail::withoutGlobalScopes()->find($this->documentEmailId);

        if ($email === null || $email->status === 'sent') {
            return;
        }

        try {
            $contact = Contact::withoutGlobalScopes()->findOrFail($email->emailable_id);
            $statement = $tenantContext->runWithTenant(
                Tenant::findOrFail($email->tenant_id),
                fn (): array => $insights->statement($contact, CarbonImmutable::parse($this->asOf))
            );

            Mail::to($email->recipient)->send(new AccountStatementMail($contact, $statement));
            $email->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $email->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
