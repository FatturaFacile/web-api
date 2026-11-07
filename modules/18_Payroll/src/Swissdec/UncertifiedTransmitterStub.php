<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

/**
 * The only transmitter there is: a stub that sends nothing and always says so. It stands behind the contract until the
 * payroll is certified. The list of what is missing is in SwissdecCertificationStatus.
 */
final class UncertifiedTransmitterStub implements SalaryDeclarationTransmitter
{
    public function transmit(array $declaration): TransmissionReceipt
    {
        throw new SwissdecTransmissionUnavailable('Trasmissione Swissdec non disponibile: questa applicazione non è certificata Swissdec e nessun dato è stato inviato.');
    }

    public function isCertified(): bool
    {
        return false;
    }
}
