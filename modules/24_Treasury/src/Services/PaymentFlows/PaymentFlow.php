<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

use App\Services\Banking\Iso20022\Pain001Version;

/**
 * The outgoing XML payment flows of the treasury workspace. The Modello F24 has its own delegation workflow
 * (see `Services/F24`) and is not an ISO 20022 flow.
 */
enum PaymentFlow: string
{
    /** Swiss Payment Standards: pain.001.001.09 with QRR / SCOR structured references. */
    case ChPain001V09 = 'ch_pain001_09';

    /** Swiss Payment Standards: pain.001.001.03 ("ch.02" generation still required by some banks), same QRR / SCOR rules. */
    case ChPain001V03 = 'ch_pain001_03';

    /** EPC SEPA credit transfer: pain.001.001.03. */
    case SepaPain001V03 = 'sepa_pain001_03';

    /** CBI interbank bonifico: the SEPA file carrying the CUC (Codice Unico CBI) of the initiating party. */
    case CbiSepaV03 = 'cbi_sepa_03';

    public function version(): Pain001Version
    {
        return $this === self::ChPain001V09 ? Pain001Version::V09 : Pain001Version::V03;
    }

    /**
     * Value of `payment_batches.format` (the column only accepts the values of the existing batch formats).
     */
    public function batchFormat(): string
    {
        return $this->isSwiss() ? 'iso_pain_001_ch' : 'sepa_pain_001_it';
    }

    public function paymentMethod(): string
    {
        return $this->isSwiss() ? 'swiss_qr_pain001' : 'sepa_credit_transfer';
    }

    public function isSepa(): bool
    {
        return ! $this->isSwiss();
    }

    public function isSwiss(): bool
    {
        return $this === self::ChPain001V09 || $this === self::ChPain001V03;
    }

    public function requiresCuc(): bool
    {
        return $this === self::CbiSepaV03;
    }

    /**
     * Currencies a flow can carry.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return $this->isSwiss() ? ['CHF', 'EUR'] : ['EUR'];
    }
}
