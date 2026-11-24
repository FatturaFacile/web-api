<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Modules\Associations\Mail\AssociationNoticeMail;
use Throwable;

/**
 * Sends the notices of the association through Laravel mail. A mail server that is down never undoes the work the
 * notice is about: the failure is reported and the caller carries on.
 */
final class AssociationMailer
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<string>  $lines
     */
    public function send(?string $email, string $subject, array $lines, ?string $actionUrl = null): void
    {
        if ($email === null || $email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new AssociationNoticeMail($subject, $lines, (string) $this->tenantContext->getTenant()?->name, $actionUrl));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
