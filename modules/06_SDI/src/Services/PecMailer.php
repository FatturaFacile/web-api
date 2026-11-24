<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Tenant;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Sends a message through the certified mailbox of the tenant.
 */
class PecMailer
{
    public function send(Tenant $tenant, Email $email): void
    {
        $port = (int) ($tenant->sdi_pec_smtp_port ?: 465);

        $transport = new EsmtpTransport((string) $tenant->sdi_pec_smtp_host, $port, $port === 465);
        $transport->setUsername((string) $tenant->sdi_pec_username);
        $transport->setPassword((string) $tenant->sdi_pec_password);

        try {
            $transport->send($email);
        } finally {
            $transport->stop();
        }
    }
}
