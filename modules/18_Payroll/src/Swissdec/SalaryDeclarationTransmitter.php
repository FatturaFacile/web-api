<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

/**
 * The contract of a transmission of a salary declaration to the Swissdec distributor. A real driver needs a payroll
 * certified by Swissdec: this application is not, and it holds no such driver.
 */
interface SalaryDeclarationTransmitter
{
    /**
     * @param  array<string, mixed>  $declaration  The structure built by SalaryDeclarationBuilder.
     *
     * @throws SwissdecTransmissionUnavailable when the transmission is not possible
     */
    public function transmit(array $declaration): TransmissionReceipt;

    public function isCertified(): bool;
}
